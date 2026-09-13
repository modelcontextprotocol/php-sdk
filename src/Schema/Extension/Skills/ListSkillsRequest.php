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
 * Sent from the client to enumerate the skills a server serves.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ListSkillsRequest extends Request
{
    /**
     * @param string|null $cursor an opaque token representing the current pagination position
     */
    public function __construct(
        public readonly ?string $cursor = null,
    ) {
    }

    public static function getMethod(): string
    {
        return 'skills/list';
    }

    protected static function fromParams(?array $params): static
    {
        if (isset($params['cursor']) && !\is_string($params['cursor'])) {
            throw new InvalidArgumentException('Invalid "cursor" parameter for skills/list.');
        }

        return new self($params['cursor'] ?? null);
    }

    /**
     * @return array{cursor: string}|null
     */
    protected function getParams(): ?array
    {
        return null !== $this->cursor ? ['cursor' => $this->cursor] : null;
    }
}
