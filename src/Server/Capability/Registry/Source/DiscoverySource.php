<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Capability\Registry\Source;

use Mcp\Server\Capability\Discovery\DiscovererInterface;

/**
 * Provides the elements a {@see DiscovererInterface} finds in a directory tree.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class DiscoverySource implements ElementSourceInterface
{
    /**
     * @param string[] $scanDirs
     * @param string[] $excludeDirs
     * @param string[] $namePatterns
     */
    public function __construct(
        private readonly string $basePath,
        private readonly array $scanDirs,
        private readonly array $excludeDirs,
        private readonly DiscovererInterface $discoverer,
        private readonly array $namePatterns = DiscovererInterface::DEFAULT_NAME_PATERNS,
    ) {
    }

    public function provide(): ElementCollection
    {
        $state = $this->discoverer->discover($this->basePath, $this->scanDirs, $this->excludeDirs, $this->namePatterns);

        return new ElementCollection($state->getTools(), $state->getResources(), $state->getResourceTemplates(), $state->getPrompts());
    }
}
