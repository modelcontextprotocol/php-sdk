<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Discovery;

/**
 * Discovers MCP elements (tools, resources, prompts, resource templates) in directories.
 *
 * @internal
 *
 * @author Antoine Bluchet <soyuka@gmail.com>
 */
interface DiscovererInterface
{
    public const DEFAULT_NAME_PATERNS = ['*.php'];

    /**
     * Discover MCP elements in the specified directories and return the discovery state.
     *
     * @param string        $basePath     the base path for resolving directories
     * @param array<string> $directories  list of directories (relative to base path) to scan
     * @param array<string> $excludeDirs  list of directories (relative to base path) to exclude from the scan
     * @param array<string> $namePatterns list of file name patterns for the scan. Compatible with Finder->name()
     */
    public function discover(string $basePath, array $directories, array $excludeDirs = [], array $namePatterns = self::DEFAULT_NAME_PATERNS): DiscoveryState;
}
