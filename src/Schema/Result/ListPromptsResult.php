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
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\Prompt;

/**
 * The server's response to a prompts/list request from the client.
 *
 * @phpstan-import-type PromptData from Prompt
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ListPromptsResult implements ResultInterface
{
    /**
     * @param array<Prompt> $prompts    the list of prompt definitions
     * @param string|null   $nextCursor An opaque token representing the pagination position after the last returned result.
     *
     * If present, there may be more results available.
     */
    public function __construct(
        public readonly array $prompts,
        public readonly ?string $nextCursor = null,
    ) {
    }

    /**
     * @param array{
     *     prompts: array<PromptData>,
     *     nextCursor?: string,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['prompts']) || !\is_array($data['prompts'])) {
            throw new InvalidArgumentException('Missing or invalid "prompts" array in ListPromptsResult data.');
        }

        return new self(
            array_map(fn (array $prompt): Prompt => Prompt::fromArray($prompt), $data['prompts']),
            $data['nextCursor'] ?? null
        );
    }

    /**
     * @return array{
     *     prompts: array<Prompt>,
     *     nextCursor?: string,
     * }
     */
    public function jsonSerialize(): array
    {
        $result = [
            'prompts' => array_values($this->prompts),
        ];

        if ($this->nextCursor) {
            $result['nextCursor'] = $this->nextCursor;
        }

        return $result;
    }
}
