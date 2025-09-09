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
 * Used by the client to get a prompt provided by the server.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class GetPromptRequest extends Request
{
    /**
     * @param string                    $name      the name of the prompt to get
     * @param array<string, mixed>|null $arguments the arguments to pass to the prompt
     */
    public function __construct(
        public readonly string $name,
        public readonly ?array $arguments = null,
    ) {
    }

    public static function getMethod(): string
    {
        return 'prompts/get';
    }

    protected static function fromParams(?array $params): static
    {
        if (!isset($params['name']) || !\is_string($params['name']) || empty($params['name'])) {
            throw new InvalidArgumentException('Missing or invalid "name" parameter for prompts/get.');
        }

        $arguments = $params['arguments'] ?? null;
        if (null !== $arguments) {
            if ($arguments instanceof \stdClass) {
                $arguments = (array) $arguments;
            }
            if (!\is_array($arguments)) {
                throw new InvalidArgumentException('Parameter "arguments" must be an array for prompts/get.');
            }
        }

        return new self($params['name'], $arguments);
    }

    protected function getParams(): ?array
    {
        $params = ['name' => $this->name];

        if (null !== $this->arguments) {
            $params['arguments'] = $this->arguments;
        }

        return $params;
    }
}
