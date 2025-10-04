<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Content;

class StructuredContent extends Content
{
    /**
     * @param mixed[] $data
     */
    public function __construct(
        private array $data = [],
    ) {
        parent::__construct('structured');
    }

    public function jsonSerialize(): mixed
    {
        return $this->data;
    }
}
