<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Concern;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Content\SamplingMessageContentBlock;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
trait SamplingMessageContentBlocks
{
    /**
     * @return list<SamplingMessageContentBlock>
     */
    public function getContentBlocks(): array
    {
        return \is_array($this->content) ? array_values($this->content) : [$this->content];
    }

    private function validateContentNotEmpty(): void
    {
        if ([] === $this->content) {
            throw new InvalidArgumentException('Sampling message content must not be empty.');
        }
    }
}
