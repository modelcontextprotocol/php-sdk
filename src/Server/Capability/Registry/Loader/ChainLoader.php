<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Capability\Registry\Loader;

use Mcp\Server\Capability\RegistryInterface;
use Mcp\Server\Exception\ConfigurationException;
use Mcp\Server\Exception\ElementSourceException;
use Mcp\Server\Exception\PartialLoadException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Composes multiple loaders into a single one. Child loaders run in the order they were given;
 * for conflicting keys, the loader that runs later wins (last-write-wins). A failing loader is logged
 * and skipped, and the chain reports the incomplete load once the others ran. A
 * {@see ConfigurationException} is a mistake in the server's own setup that no retry fixes, so it
 * ends the load instead.
 *
 * The next load of that registry only retries what failed. A loader that recovers, or that may have
 * written before it failed, is followed by all loaders after it, so that last-write-wins gives the
 * result of an uninterrupted run. An {@see ElementSourceException} means that nothing was written.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ChainLoader implements LoaderInterface
{
    /**
     * @var \WeakMap<RegistryInterface, array<int, true>>
     */
    private \WeakMap $failed;

    /**
     * @param LoaderInterface[] $loaders
     */
    public function __construct(
        private readonly array $loaders,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->failed = new \WeakMap();
    }

    public function load(RegistryInterface $registry): void
    {
        $retry = $this->failed[$registry] ?? null;
        $runRemaining = null === $retry;
        $failed = [];

        foreach (array_values($this->loaders) as $position => $loader) {
            if (!$runRemaining && !isset($retry[$position])) {
                continue;
            }

            try {
                $loader->load($registry);
                $runRemaining = true;
            } catch (ConfigurationException $e) {
                unset($this->failed[$registry]);

                throw $e;
            } catch (\Throwable $e) { // loading runs inside request handling, so nothing a source throws may end it
                $failed[$position] = true;
                $runRemaining = $runRemaining || !$e instanceof ElementSourceException;
                $this->logger->error(\sprintf('Loading elements from "%s" failed: %s', $loader::class, $e->getMessage()), ['exception' => $e]);
            }
        }

        if ([] === $failed) {
            unset($this->failed[$registry]);

            return;
        }

        $this->failed[$registry] = $failed;

        throw new PartialLoadException('Some element loaders failed.');
    }
}
