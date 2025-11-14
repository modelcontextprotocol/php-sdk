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

use Mcp\Capability\Discovery\CachedDiscoverer;
use Mcp\Capability\Discovery\Discoverer;
use Mcp\Capability\RegistryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * @author Antoine Bluchet <soyuka@gmail.com>
 */
final class DiscoveryLoader implements LoaderInterface
{
    /**
     * @param string[]       $scanDirs
     * @param array|string[] $excludeDirs
     */
    public function __construct(
        private string $basePath,
        private array $scanDirs,
        private array $excludeDirs,
        private LoggerInterface $logger,
        private ?CacheInterface $cache = null,
    ) {
    }

    public function load(RegistryInterface $registry): void
    {
        // This now encapsulates the discovery process
        $discoverer = new Discoverer($this->logger);

        $cachedDiscoverer = $this->cache
            ? new CachedDiscoverer($discoverer, $this->cache, $this->logger)
            : $discoverer;

        $discoveryState = $cachedDiscoverer->discover($this->basePath, $this->scanDirs, $this->excludeDirs);

        $registry->setDiscoveryState($discoveryState);
    }
}
