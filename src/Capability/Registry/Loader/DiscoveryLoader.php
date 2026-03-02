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

use Mcp\Capability\Discovery\DiscovererInterface;
use Mcp\Capability\RegistryInterface;

/**
 * @internal
 *
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
        private DiscovererInterface $discoverer,
    ) {
    }

    public function load(RegistryInterface $registry): void
    {
        $discoveryState = $this->discoverer->discover($this->basePath, $this->scanDirs, $this->excludeDirs);

        $registry->setDiscoveryState($discoveryState);
    }
}
