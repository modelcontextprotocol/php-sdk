<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Registry\Loader;

use Mcp\Capability\RegistryInterface;

/**
 * Composes multiple loaders into a single one. Child loaders run in the order they were given;
 * for conflicting keys, the loader that runs later wins (last-write-wins).
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ChainLoader implements LoaderInterface
{
    /**
     * @param LoaderInterface[] $loaders
     */
    public function __construct(
        private readonly array $loaders,
    ) {
    }

    public function load(RegistryInterface $registry): void
    {
        foreach ($this->loaders as $loader) {
            $loader->load($registry);
        }
    }
}
