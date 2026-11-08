<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Request;

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
     * @param array<string, mixed> $arguments the arguments to pass to the tool; when decoded from JSON,
     *                                        nested objects are `\stdClass` and nested arrays are lists
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

    protected static function paramsToArray(\stdClass $params): array
    {
        $array = parent::paramsToArray($params);

        // Arguments are free-form JSON validated against a schema, so `{}` and `[]` must stay apart.
        if (isset($params->arguments)) {
            $array['arguments'] = $params->arguments;
        }

        return $array;
    }

    protected static function fromParams(?array $params): static
    {
        if (!isset($params['name']) || !\is_string($params['name'])) {
            throw new InvalidArgumentException('Missing or invalid "name" parameter for tools/call.');
        }

        $arguments = \array_key_exists('arguments', $params) ? $params['arguments'] : new \stdClass();

        if (\is_array($arguments) && array_is_list($arguments) || !\is_array($arguments) && !$arguments instanceof \stdClass) {
            throw new InvalidArgumentException('Parameter "arguments" must be an object.');
        }

        return new self(
            $params['name'],
            (array) $arguments,
        );
    }

    /**
     * @return array{name: string, arguments: \stdClass}
     */
    protected function getParams(): array
    {
        return [
            'name' => $this->name,
            'arguments' => (object) $this->arguments,
        ];
    }
}
