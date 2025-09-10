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
use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Enum\Role;

/**
 * Optional annotations for the client. The client can use annotations
 * to inform how objects are used or displayed.
 *
 * @phpstan-type AnnotationsData array{
 *     audience?: string[],
 *     priority?: float
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class Annotations implements JsonSerializable
{
    /**
     * @param Role[]|null $audience Describes who the intended customer of this object or data is.
     *
     *  It can include multiple entries to indicate content useful for multiple audiences (e.g., `[Role::User, Role::Assistant]`).
     * @param float|null $priority Describes how important this data is for operating the server.
     *
     * A value of 1 means "most important," and indicates that the data is
     * effectively required, while 0 means "least important," and indicates that
     * the data is entirely optional.
     */
    public function __construct(
        public readonly ?array $audience = null,
        public readonly ?float $priority = null,
    ) {
        if (null !== $this->priority && ($this->priority < 0 || $this->priority > 1)) {
            throw new InvalidArgumentException('Annotation priority must be between 0 and 1.');
        }
        if (null !== $this->audience) {
            foreach ($this->audience as $role) {
                if (!($role instanceof Role)) {
                    throw new InvalidArgumentException('All audience members must be instances of Role enum.');
                }
            }
        }
    }

    /**
     * @param AnnotationsData $data
     */
    public static function fromArray(array $data): self
    {
        $audience = null;
        if (isset($data['audience']) && \is_array($data['audience'])) {
            $audience = array_map(fn (string $r) => Role::from($r), $data['audience']);
        }

        return new self(
            $audience,
            $data['priority'] ?? null
        );
    }

    /**
     * @return AnnotationsData
     */
    public function jsonSerialize(): array
    {
        $data = [];
        if (null !== $this->audience) {
            $data['audience'] = array_map(fn (Role $r) => $r->value, $this->audience);
        }
        if (null !== $this->priority) {
            $data['priority'] = $this->priority;
        }

        return $data;
    }
}
