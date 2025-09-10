<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Schema;

use JsonSerializable;

/**
 * Identifies a prompt.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class PromptReference implements JsonSerializable
{
    public string $type = 'ref/prompt';

    /**
     * @param string $name The name of the prompt or prompt template
     */
    public function __construct(
        public readonly string $name,
    ) {
    }

    /**
     * @return array{
     *     type: string,
     *     name: string,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type,
            'name' => $this->name,
        ];
    }
}
