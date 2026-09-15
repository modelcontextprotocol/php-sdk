<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Extension\Skills;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\Request;

/**
 * Sent from the client to the server, to get the entry for a single skill by
 * the URI of its SKILL.md.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class GetSkillRequest extends Request
{
    /**
     * @param non-empty-string $uri URI of the skill's SKILL.md
     */
    public function __construct(
        public readonly string $uri,
    ) {
    }

    public static function getMethod(): string
    {
        return 'skills/get';
    }

    protected static function fromParams(?array $params): static
    {
        if (!isset($params['uri']) || !\is_string($params['uri']) || '' === $params['uri']) {
            throw new InvalidArgumentException('Missing or invalid "uri" parameter for skills/get.');
        }

        return new self($params['uri']);
    }

    /**
     * @return array{uri: non-empty-string}
     */
    protected function getParams(): array
    {
        return ['uri' => $this->uri];
    }
}
