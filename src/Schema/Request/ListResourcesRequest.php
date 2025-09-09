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

use Mcp\Schema\JsonRpc\Request;

/**
 * Sent from the client to request a list of resources the server has.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class ListResourcesRequest extends Request
{
    /**
     * @param string|null $cursor An opaque token representing the current pagination position.
     *
     * If provided, the server should return results starting after this cursor.
     */
    public function __construct(
        public readonly ?string $cursor = null,
    ) {
    }

    public static function getMethod(): string
    {
        return 'resources/list';
    }

    protected static function fromParams(?array $params): static
    {
        return new self($params['cursor'] ?? null);
    }

    /**
     * @return array{cursor:string}|null
     */
    protected function getParams(): ?array
    {
        $params = [];
        if (null !== $this->cursor) {
            $params['cursor'] = $this->cursor;
        }

        return $params ?: null;
    }
}
