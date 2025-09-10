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

namespace Mcp\Schema\Result;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\JsonRpc\ResultInterface;

/**
 * @phpstan-import-type PromptMessageData from PromptMessage
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class GetPromptResult implements ResultInterface
{
    /**
     * Create a new GetPromptResult.
     *
     * @param PromptMessage[] $messages    The messages in the prompt
     * @param string|null     $description Optional description of the prompt
     */
    public function __construct(
        public readonly array $messages,
        public readonly ?string $description = null,
    ) {
        foreach ($this->messages as $message) {
            if (!$message instanceof PromptMessage) {
                throw new InvalidArgumentException('Messages must be an array of PromptMessage objects.');
            }
        }
    }

    /**
     * @param array{
     *     messages: array<PromptMessageData>,
     *     description?: string,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['messages']) || !\is_array($data['messages'])) {
            throw new InvalidArgumentException('Missing or invalid "messages" array in GetPromptResult data.');
        }

        $messages = [];
        foreach ($data['messages'] as $message) {
            $messages[] = PromptMessage::fromArray($message);
        }

        return new self($messages, $data['description'] ?? null);
    }

    /**
     * @return array{
     *     messages: array<PromptMessage>,
     *     description?: string,
     * }
     */
    public function jsonSerialize(): array
    {
        $result = [
            'messages' => $this->messages,
        ];

        if (null !== $this->description) {
            $result['description'] = $this->description;
        }

        return $result;
    }
}
