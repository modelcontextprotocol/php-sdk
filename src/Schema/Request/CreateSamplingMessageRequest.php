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
use Mcp\Schema\Content\SamplingMessage;
use Mcp\Schema\Enum\SamplingContext;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\ModelPreferences;

/**
 * A request from the server to sample an LLM via the client. The client has full discretion over which model to select.
 * The client should also inform the user before beginning sampling, to allow them to inspect the request (human in the
 * loop) and decide whether to approve it.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class CreateSamplingMessageRequest extends Request
{
    /**
     * @param SamplingMessage[]     $messages       the messages to send to the model
     * @param int                   $maxTokens      The maximum number of tokens to sample, as requested by the server.
     *                                              The client MAY choose to sample fewer tokens than requested.
     * @param ?ModelPreferences     $preferences    The server's preferences for which model to select. The client MAY
     *                                              ignore these preferences.
     * @param ?string               $systemPrompt   An optional system prompt the server wants to use for sampling. The
     *                                              client MAY modify or omit this prompt.
     * @param ?SamplingContext      $includeContext A request to include context from one or more MCP servers (including
     *                                              the caller), to be attached to the prompt. The client MAY ignore this request.
     *                                              Allowed values: "none", "thisServer", "allServers"
     * @param ?float                $temperature    The temperature to use for sampling. The client MAY ignore this request.
     * @param ?string[]             $stopSequences  A list of sequences to stop sampling at. The client MAY ignore this request.
     * @param ?array<string, mixed> $metadata       Optional metadata to pass through to the LLM provider. The format of
     *                                              this metadata is provider-specific.
     */
    public function __construct(
        public readonly array $messages,
        public readonly int $maxTokens,
        public readonly ?ModelPreferences $preferences = null,
        public readonly ?string $systemPrompt = null,
        public readonly ?SamplingContext $includeContext = null,
        public readonly ?float $temperature = null,
        public readonly ?array $stopSequences = null,
        public readonly ?array $metadata = null,
    ) {
        foreach ($this->messages as $message) {
            if (!$message instanceof SamplingMessage) {
                throw new InvalidArgumentException('Messages must be instance of SamplingMessage.');
            }
        }
    }

    public static function getMethod(): string
    {
        return 'sampling/createMessage';
    }

    protected static function fromParams(?array $params): static
    {
        if (!isset($params['messages']) || !\is_array($params['messages'])) {
            throw new InvalidArgumentException('Missing or invalid "messages" parameter for sampling/createMessage.');
        }

        if (!isset($params['maxTokens']) || !\is_int($params['maxTokens'])) {
            throw new InvalidArgumentException('Missing or invalid "maxTokens" parameter for sampling/createMessage.');
        }

        $preferences = null;
        if (isset($params['preferences'])) {
            $preferences = ModelPreferences::fromArray($params['preferences']);
        }

        return new self(
            $params['messages'],
            $params['maxTokens'],
            $preferences,
            $params['systemPrompt'] ?? null,
            $params['includeContext'] ?? null,
            $params['temperature'] ?? null,
            $params['stopSequences'] ?? null,
            $params['metadata'] ?? null,
        );
    }

    /**
     * @return array{
     *     messages: SamplingMessage[],
     *     maxTokens: int,
     *     preferences?: ModelPreferences,
     *     systemPrompt?: string,
     *     includeContext?: string,
     *     temperature?: float,
     *     stopSequences?: string[],
     *     metadata?: array<string, mixed>
     * }
     */
    protected function getParams(): array
    {
        $params = [
            'messages' => $this->messages,
            'maxTokens' => $this->maxTokens,
        ];

        if (null !== $this->preferences) {
            $params['preferences'] = $this->preferences;
        }

        if (null !== $this->systemPrompt) {
            $params['systemPrompt'] = $this->systemPrompt;
        }

        if (null !== $this->includeContext) {
            $params['includeContext'] = $this->includeContext->value;
        }

        if (null !== $this->temperature) {
            $params['temperature'] = $this->temperature;
        }

        if (null !== $this->stopSequences) {
            $params['stopSequences'] = $this->stopSequences;
        }

        if (null !== $this->metadata) {
            $params['metadata'] = $this->metadata;
        }

        return $params;
    }
}
