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
use Mcp\Schema\PromptReference;
use Mcp\Schema\ResourceReference;

/**
 * A request from the client to the server, to ask for completion options.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class CompletionCompleteRequest extends Request
{
    /**
     * @param PromptReference|ResourceReference    $ref      the prompt or resource to complete
     * @param array{ name: string, value: string } $argument the argument to complete
     */
    public function __construct(
        public readonly PromptReference|ResourceReference $ref,
        public readonly array $argument,
    ) {
    }

    public static function getMethod(): string
    {
        return 'completion/complete';
    }

    protected static function fromParams(?array $params): static
    {
        if (!isset($params['ref']) || !\is_array($params['ref'])) {
            throw new InvalidArgumentException('Missing or invalid "ref" parameter for completion/complete.');
        }

        $ref = match ($params['ref']['type'] ?? null) {
            'ref/prompt' => new PromptReference(self::stringMember($params['ref'], 'ref', 'name')),
            'ref/resource' => new ResourceReference(self::stringMember($params['ref'], 'ref', 'uri')),
            default => throw new InvalidArgumentException('Invalid "ref" parameter for completion/complete.'),
        };

        if (!isset($params['argument']) || !\is_array($params['argument'])) {
            throw new InvalidArgumentException('Missing or invalid "argument" parameter for completion/complete.');
        }

        return new self($ref, [
            'name' => self::stringMember($params['argument'], 'argument', 'name'),
            'value' => self::stringMember($params['argument'], 'argument', 'value'),
        ]);
    }

    /**
     * @param array<mixed> $data
     */
    private static function stringMember(array $data, string $member, string $key): string
    {
        if (!isset($data[$key]) || !\is_string($data[$key])) {
            throw new InvalidArgumentException(\sprintf('Missing or invalid "%s.%s" parameter for completion/complete.', $member, $key));
        }

        return $data[$key];
    }

    /**
     * @return array{
     *     ref: PromptReference|ResourceReference,
     *     argument: array{ name: string, value: string }
     * }
     */
    protected function getParams(): array
    {
        return [
            'ref' => $this->ref,
            'argument' => $this->argument,
        ];
    }
}
