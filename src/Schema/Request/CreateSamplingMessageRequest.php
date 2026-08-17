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
use Mcp\Schema\Content\ToolResultContent;
use Mcp\Schema\Content\ToolUseContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Enum\SamplingContext;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\ModelPreferences;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolChoice;

/**
 * A request from the server to sample an LLM via the client. The client has full discretion over which model to select.
 * The client should also inform the user before beginning sampling, to allow them to inspect the request (human in the
 * loop) and decide whether to approve it.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 *
 * @deprecated Deprecated as of protocol revision 2026-07-28 (SEP-2577). Still functional for at
 * least twelve months. Integrate with an LLM provider's API directly instead.
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
     *                                              Values other than "none" are soft-deprecated and SHOULD only be sent
     *                                              when the client advertises the sampling.context capability.
     * @param ?float                $temperature    The temperature to use for sampling. The client MAY ignore this request.
     * @param ?string[]             $stopSequences  A list of sequences to stop sampling at. The client MAY ignore this request.
     * @param ?array<string, mixed> $metadata       Optional metadata to pass through to the LLM provider. The format of
     *                                              this metadata is provider-specific.
     * @param ?Tool[]               $tools          tools that the model may use during generation
     * @param ?ToolChoice           $toolChoice     controls how the model uses tools
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
        public readonly ?array $tools = null,
        public readonly ?ToolChoice $toolChoice = null,
    ) {
        foreach ($this->messages as $message) {
            if (!$message instanceof SamplingMessage) {
                throw new InvalidArgumentException('Messages must be instance of SamplingMessage.');
            }
        }
        foreach ($this->tools ?? [] as $tool) {
            if (!$tool instanceof Tool) {
                throw new InvalidArgumentException('Tools must be instances of Tool.');
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

        $messages = [];
        foreach ($params['messages'] as $messageData) {
            if ($messageData instanceof SamplingMessage) {
                $messages[] = $messageData;
            } elseif (\is_array($messageData)) {
                $messages[] = SamplingMessage::fromArray($messageData);
            } else {
                throw new InvalidArgumentException('Invalid message format in sampling/createMessage.');
            }
        }

        $preferences = null;
        if (isset($params['preferences'])) {
            if (!\is_array($params['preferences'])) {
                throw new InvalidArgumentException('Invalid "preferences" parameter for sampling/createMessage.');
            }
            $preferences = ModelPreferences::fromArray($params['preferences']);
        }

        $includeContext = null;
        if (isset($params['includeContext']) && \is_string($params['includeContext'])) {
            $includeContext = SamplingContext::tryFrom($params['includeContext']);
        }

        if (isset($params['systemPrompt']) && !\is_string($params['systemPrompt'])) {
            throw new InvalidArgumentException('Invalid "systemPrompt" parameter for sampling/createMessage.');
        }

        if (isset($params['temperature']) && !\is_float($params['temperature']) && !\is_int($params['temperature'])) {
            throw new InvalidArgumentException('Invalid "temperature" parameter for sampling/createMessage.');
        }

        if (isset($params['stopSequences'])) {
            if (!\is_array($params['stopSequences'])) {
                throw new InvalidArgumentException('Invalid "stopSequences" parameter for sampling/createMessage.');
            }

            foreach ($params['stopSequences'] as $stopSequence) {
                if (!\is_string($stopSequence)) {
                    throw new InvalidArgumentException('Each entry in "stopSequences" must be a string for sampling/createMessage.');
                }
            }
        }

        if (isset($params['metadata']) && !\is_array($params['metadata'])) {
            throw new InvalidArgumentException('Invalid "metadata" parameter for sampling/createMessage.');
        }

        $tools = null;
        if (isset($params['tools'])) {
            if (!\is_array($params['tools'])) {
                throw new InvalidArgumentException('Invalid "tools" parameter for sampling/createMessage.');
            }
            $tools = [];
            foreach ($params['tools'] as $toolData) {
                if ($toolData instanceof Tool) {
                    $tools[] = $toolData;
                } elseif (\is_array($toolData)) {
                    $tools[] = Tool::fromArray($toolData);
                } else {
                    throw new InvalidArgumentException('Invalid tool format in sampling/createMessage.');
                }
            }
        }

        $toolChoice = null;
        if (isset($params['toolChoice'])) {
            if ($params['toolChoice'] instanceof ToolChoice) {
                $toolChoice = $params['toolChoice'];
            } elseif (\is_array($params['toolChoice'])) {
                $toolChoice = ToolChoice::fromArray($params['toolChoice']);
            } else {
                throw new InvalidArgumentException('Invalid "toolChoice" parameter for sampling/createMessage.');
            }
        }

        return new self(
            $messages,
            $params['maxTokens'],
            $preferences,
            $params['systemPrompt'] ?? null,
            $includeContext,
            isset($params['temperature']) ? (float) $params['temperature'] : null,
            $params['stopSequences'] ?? null,
            $params['metadata'] ?? null,
            $tools,
            $toolChoice,
        );
    }

    /**
     * Assert the spec's tool-flow rules over the whole message list.
     *
     * These are deliberately kept out of the hydration path: a violation is an
     * "invalid params" condition the peer must be told about, not a parse failure
     * that would leave the request unanswered. Call it from whatever boundary can
     * report it — the request handler when receiving, the gateway when sending.
     *
     * @throws InvalidArgumentException on the first violation found
     */
    public function validateToolFlow(): void
    {
        $pendingToolUseIds = [];

        foreach ($this->messages as $message) {
            $blocks = $message->getContentBlocks();

            $toolResults = array_filter($blocks, static fn ($block): bool => $block instanceof ToolResultContent);
            $toolUses = array_filter($blocks, static fn ($block): bool => $block instanceof ToolUseContent);

            if ($toolResults && \count($toolResults) !== \count($blocks)) {
                throw new InvalidArgumentException('Tool results mixed with other content.');
            }

            if (Role::User === $message->role && $toolUses) {
                throw new InvalidArgumentException('ToolUseContent is only valid in assistant sampling messages.');
            }

            if (Role::Assistant === $message->role && $toolResults) {
                throw new InvalidArgumentException('ToolResultContent is only valid in user sampling messages.');
            }

            if ($pendingToolUseIds && !$toolResults) {
                throw new InvalidArgumentException('Tool result missing in request.');
            }

            foreach ($toolResults as $toolResult) {
                $matched = array_search($toolResult->toolUseId, $pendingToolUseIds, true);
                if (false === $matched) {
                    throw new InvalidArgumentException(\sprintf('Tool result "%s" does not answer a preceding tool use.', $toolResult->toolUseId));
                }
                unset($pendingToolUseIds[$matched]);
            }

            if ($pendingToolUseIds) {
                throw new InvalidArgumentException('Tool result missing in request.');
            }

            foreach ($toolUses as $toolUse) {
                $pendingToolUseIds[] = $toolUse->id;
            }
        }

        if ($pendingToolUseIds) {
            throw new InvalidArgumentException('Tool result missing in request.');
        }
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
     *     metadata?: array<string, mixed>,
     *     tools?: Tool[],
     *     toolChoice?: ToolChoice,
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

        if (null !== $this->tools) {
            $params['tools'] = $this->tools;
        }

        if (null !== $this->toolChoice) {
            $params['toolChoice'] = $this->toolChoice;
        }

        return $params;
    }
}
