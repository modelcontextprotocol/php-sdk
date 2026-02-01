<?php

declare(strict_types=1);

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Result;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\JsonRpc\ResultInterface;

/**
 * The client's response to an elicitation/create request from the server.
 *
 * Contains the user's action (accept, decline, or cancel) and the content
 * they provided when accepting.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ElicitResult implements ResultInterface
{
    /**
     * @param ElicitAction              $action  The user's action in response to the elicitation
     * @param array<string, mixed>|null $content The content provided by the user (only present when action is "accept")
     */
    public function __construct(
        public readonly ElicitAction $action,
        public readonly ?array $content = null,
    ) {
    }

    /**
     * @param array{action: string, content?: array<string, mixed>} $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['action']) || !\is_string($data['action'])) {
            throw new InvalidArgumentException('Missing or invalid "action" in ElicitResult data.');
        }

        $action = ElicitAction::from($data['action']);
        $content = isset($data['content']) && \is_array($data['content']) ? $data['content'] : null;

        if (ElicitAction::Accept === $action && null === $content) {
            throw new InvalidArgumentException('Content must be provided when action is "accept".');
        }

        return new self($action, $content);
    }

    public function isAccepted(): bool
    {
        return ElicitAction::Accept === $this->action;
    }

    public function isDeclined(): bool
    {
        return ElicitAction::Decline === $this->action;
    }

    public function isCancelled(): bool
    {
        return ElicitAction::Cancel === $this->action;
    }

    /**
     * @return array{action: string, content?: array<string, mixed>}
     */
    public function jsonSerialize(): array
    {
        $result = [
            'action' => $this->action->value,
        ];

        if (null !== $this->content) {
            $result['content'] = $this->content;
        }

        return $result;
    }
}
