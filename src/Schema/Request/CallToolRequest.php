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

namespace Mcp\Schema\Request;

use stdClass;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\Request;

/**
 * Used by the client to invoke a tool provided by the server.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class CallToolRequest extends Request
{
    /**
     * @param string               $name      the name of the tool to invoke
     * @param array<string, mixed> $arguments the arguments to pass to the tool
     */
    public function __construct(
        public readonly string $name,
        public readonly array $arguments,
    ) {
    }

    public static function getMethod(): string
    {
        return 'tools/call';
    }

    protected static function fromParams(?array $params): static
    {
        if (!isset($params['name']) || !\is_string($params['name'])) {
            throw new InvalidArgumentException('Missing or invalid "name" parameter for tools/call.');
        }

        $arguments = $params['arguments'] ?? [];

        if ($arguments instanceof stdClass) {
            $arguments = (array) $arguments;
        }

        if (!\is_array($arguments)) {
            throw new InvalidArgumentException('Parameter "arguments" must be an array.');
        }

        return new self(
            $params['name'],
            $arguments,
        );
    }

    /**
     * @return array{name: string, arguments: array<string, mixed>}
     */
    protected function getParams(): array
    {
        return [
            'name' => $this->name,
            'arguments' => $this->arguments,
        ];
    }
}
