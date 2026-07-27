<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Formatter;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\RuntimeException;
use Mcp\Schema\Content\AudioContent;
use Mcp\Schema\Content\Content;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 * @author Mateu Aguiló Bosch <mateu@mateuaguilo.com>
 */
final class PromptResultFormatter
{
    /**
     * Formats the raw result of a prompt generator into an array of MCP PromptMessages.
     *
     * @param mixed $promptGenerationResult expected: array of message structures
     *
     * @return PromptMessage[] array of PromptMessage objects
     *
     * @throws \RuntimeException if the result cannot be formatted
     * @throws \JsonException    if JSON encoding fails
     */
    public function format(mixed $promptGenerationResult): array
    {
        if ($promptGenerationResult instanceof PromptMessage) {
            return [$promptGenerationResult];
        }

        if (!\is_array($promptGenerationResult)) {
            throw new RuntimeException('Prompt generator method must return an array of messages.');
        }

        if (empty($promptGenerationResult)) {
            return [];
        }

        if (\is_array($promptGenerationResult)) {
            $allArePromptMessages = true;
            $hasPromptMessages = false;

            foreach ($promptGenerationResult as $item) {
                if ($item instanceof PromptMessage) {
                    $hasPromptMessages = true;
                } else {
                    $allArePromptMessages = false;
                }
            }

            if ($allArePromptMessages && $hasPromptMessages) {
                return $promptGenerationResult;
            }

            if ($hasPromptMessages) {
                $result = [];
                foreach ($promptGenerationResult as $index => $item) {
                    if ($item instanceof PromptMessage) {
                        $result[] = $item;
                    } else {
                        $result = array_merge($result, $this->format($item));
                    }
                }

                return $result;
            }

            if (!array_is_list($promptGenerationResult)) {
                if (isset($promptGenerationResult['user']) || isset($promptGenerationResult['assistant'])) {
                    $result = [];
                    if (isset($promptGenerationResult['user'])) {
                        $userContent = $this->formatContent($promptGenerationResult['user']);
                        $result[] = new PromptMessage(Role::User, $userContent);
                    }
                    if (isset($promptGenerationResult['assistant'])) {
                        $assistantContent = $this->formatContent($promptGenerationResult['assistant']);
                        $result[] = new PromptMessage(Role::Assistant, $assistantContent);
                    }

                    return $result;
                }

                if (isset($promptGenerationResult['role']) && isset($promptGenerationResult['content'])) {
                    return [$this->formatMessage($promptGenerationResult)];
                }

                throw new RuntimeException('Associative array must contain either role/content keys or user/assistant keys.');
            }

            $formattedMessages = [];
            foreach ($promptGenerationResult as $index => $message) {
                if ($message instanceof PromptMessage) {
                    $formattedMessages[] = $message;
                } else {
                    $formattedMessages[] = $this->formatMessage($message, $index);
                }
            }

            return $formattedMessages;
        }

        throw new RuntimeException('Invalid prompt generation result format.');
    }

    /**
     * Formats a single message into a PromptMessage.
     */
    private function formatMessage(mixed $message, ?int $index = null): PromptMessage
    {
        $indexStr = null !== $index ? " at index {$index}" : '';

        if (!\is_array($message) || !\array_key_exists('role', $message) || !\array_key_exists('content', $message)) {
            throw new RuntimeException("Invalid message format{$indexStr}. Expected an array with 'role' and 'content' keys.");
        }

        $role = $message['role'] instanceof Role ? $message['role'] : Role::tryFrom($message['role']);
        if (null === $role) {
            throw new RuntimeException("Invalid role '{$message['role']}' in prompt message{$indexStr}. Only 'user' or 'assistant' are supported.");
        }

        $content = $this->formatContent($message['content'], $index);

        return new PromptMessage($role, $content);
    }

    /**
     * Formats content into a proper Content object.
     */
    private function formatContent(mixed $content, ?int $index = null): TextContent|ImageContent|AudioContent|EmbeddedResource
    {
        $indexStr = null !== $index ? " at index {$index}" : '';

        if ($content instanceof Content) {
            if (
                $content instanceof TextContent || $content instanceof ImageContent
                || $content instanceof AudioContent || $content instanceof EmbeddedResource
            ) {
                return $content;
            }
            throw new RuntimeException("Invalid Content type{$indexStr}. PromptMessage only supports TextContent, ImageContent, AudioContent, or EmbeddedResource.");
        }

        if (\is_string($content)) {
            return new TextContent($content);
        }

        if (\is_array($content) && isset($content['type'])) {
            return $this->formatTypedContent($content, $index);
        }

        if (\is_scalar($content) || null === $content) {
            $stringContent = null === $content ? '(null)' : (\is_bool($content) ? ($content ? 'true' : 'false') : (string) $content);

            return new TextContent($stringContent);
        }

        $jsonContent = json_encode($content, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        return new TextContent($jsonContent);
    }

    /**
     * Formats typed content arrays into Content objects.
     *
     * Delegates to the schema classes' fromArray() so optional fields
     * (annotations, mimeType, _meta, ...) carry over instead of being dropped.
     *
     * @param array<string, mixed> $content
     */
    private function formatTypedContent(array $content, ?int $index = null): TextContent|ImageContent|AudioContent|EmbeddedResource
    {
        $indexStr = null !== $index ? " at index {$index}" : '';
        $type = $content['type'];

        if ('resource' === $type && isset($content['resource']) && \is_array($content['resource']) && !isset($content['resource']['mimeType'])) {
            // EmbeddedResource::fromArray() leaves a missing mimeType unset; this
            // formatter has always defaulted it, so keep that for compatibility.
            $content['resource']['mimeType'] = isset($content['resource']['text']) ? 'text/plain' : 'application/octet-stream';
        }

        try {
            return match ($type) {
                'text' => TextContent::fromArray($content),
                'image' => ImageContent::fromArray($content),
                'audio' => AudioContent::fromArray($content),
                'resource' => EmbeddedResource::fromArray($content),
                default => throw new RuntimeException("Invalid content type '{$type}'{$indexStr}."),
            };
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException("Invalid '{$type}' content{$indexStr}: {$e->getMessage()}", 0, $e);
        }
    }
}
