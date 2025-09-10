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
 * Hints to use for model selection.
 *
 * Keys not declared here are currently left unspecified by the spec and are up to the client to interpret.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ModelHint implements JsonSerializable
{
    /**
     * @param string|null $name A hint for a model name.
     *
     * The client SHOULD treat this as a substring of a model name; for example:
     *  - `claude-3-5-sonnet` should match `claude-3-5-sonnet-20241022`
     *  - `sonnet` should match `claude-3-5-sonnet-20241022`, `claude-3-sonnet-20240229`, etc.
     *  - `claude` should match any Claude model
     *
     * The client MAY also map the string to a different provider's model name or a different model family, as long as it fills a similar niche; for example:
     *  - `gemini-1.5-flash` could match `claude-3-haiku-20240307`
     */
    public function __construct(
        public readonly ?string $name = null,
    ) {
    }

    /**
     * @return array{name: string}|array{}
     */
    public function jsonSerialize(): array
    {
        if (null === $this->name) {
            return [];
        }

        return ['name' => $this->name];
    }
}
