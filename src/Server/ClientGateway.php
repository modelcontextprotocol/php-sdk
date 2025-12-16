<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server;

use Mcp\Exception\ClientException;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\RuntimeException;
use Mcp\Schema\Content\AudioContent;
use Mcp\Schema\Content\Content;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\SamplingMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Enum\SamplingContext;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\ModelPreferences;
use Mcp\Schema\Notification\LoggingMessageNotification;
use Mcp\Schema\Notification\ProgressNotification;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Result\CreateSamplingMessageResult;
use Mcp\Server\Session\SessionInterface;

/**
 * @final
 * Helper class for tools to communicate with the client.
 *
 * This class provides a clean API for element handlers to send requests and notifications
 * to the client. It uses PHP Fibers internally to make the communication appear
 * synchronous while the transport handles all blocking operations.
 *
 * Example usage in a tool:
 * ```php
 * public function analyze(string $text, RequestContext $context): string {
 *     $client = $context->getClientGateway();
 *     // Send progress notification
 *     $client->notify(new ProgressNotification("Starting analysis..."));
 *
 *     // Request LLM sampling from client
 *     $response = $client->request(new SamplingRequest($text));
 *
 *     return $response->content->text;
 * }
 * ```
 *
 * @phpstan-type SampleOptions array{
 *     preferences?: ModelPreferences,
 *     systemPrompt?: string,
 *     temperature?: float,
 *     includeContext?: SamplingContext,
 *     stopSequences?: string[],
 *     metadata?: array<string, mixed>,
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ClientGateway
{
    public function __construct(
        private readonly SessionInterface $session,
    ) {
    }

    /**
     * Send a notification to the client (fire and forget).
     *
     * This suspends the Fiber to let the transport flush the notification via SSE,
     * then immediately resumes execution.
     */
    public function notify(Notification $notification): void
    {
        \Fiber::suspend([
            'type' => 'notification',
            'notification' => $notification,
            'session_id' => $this->session->getId()->toRfc4122(),
        ]);
    }

    /**
     * Convenience method to send a logging notification to the client.
     */
    public function log(LoggingLevel $level, mixed $data, ?string $logger = null): void
    {
        $this->notify(new LoggingMessageNotification($level, $data, $logger));
    }

    /**
     * Convenience method to send a progress notification to the client.
     */
    public function progress(float $progress, ?float $total = null, ?string $message = null): void
    {
        $meta = $this->session->get(Protocol::SESSION_ACTIVE_REQUEST_META, []);
        $progressToken = $meta['progressToken'] ?? null;

        if (null === $progressToken) {
            // Per the spec the client never asked for progress, so just bail.
            return;
        }

        $this->notify(new ProgressNotification($progressToken, $progress, $total, $message));
    }

    /**
     * Convenience method for LLM sampling requests.
     *
     * @param SamplingMessage[]|TextContent|AudioContent|ImageContent|string $message   The message for the LLM
     * @param int                                                            $maxTokens Maximum tokens to generate
     * @param int                                                            $timeout   The timeout in seconds
     * @param SampleOptions                                                  $options   Additional sampling options (temperature, etc.)
     *
     * @return CreateSamplingMessageResult The sampling response
     *
     * @throws ClientException if the client request results in an error message
     */
    public function sample(array|Content|string $message, int $maxTokens = 1000, int $timeout = 120, array $options = []): CreateSamplingMessageResult
    {
        $preferences = $options['preferences'] ?? null;
        if (null !== $preferences && !$preferences instanceof ModelPreferences) {
            throw new InvalidArgumentException('The "preferences" option must be an array or an instance of ModelPreferences.');
        }

        if (\is_string($message)) {
            $message = new TextContent($message);
        }
        if (\is_object($message) && \in_array($message::class, [TextContent::class, AudioContent::class, ImageContent::class], true)) {
            $message = [new SamplingMessage(Role::User, $message)];
        }

        $request = new CreateSamplingMessageRequest(
            messages: $message,
            maxTokens: $maxTokens,
            preferences: $preferences,
            systemPrompt: $options['systemPrompt'] ?? null,
            includeContext: $options['includeContext'] ?? null,
            temperature: $options['temperature'] ?? null,
            stopSequences: $options['stopSequences'] ?? null,
            metadata: $options['metadata'] ?? null,
        );

        $response = $this->request($request, $timeout);

        if ($response instanceof Error) {
            throw new ClientException($response);
        }

        return CreateSamplingMessageResult::fromArray($response->result);
    }

    /**
     * Send a request to the client and wait for a response (blocking).
     *
     * This suspends the Fiber and waits for the client to respond. The transport
     * handles polling the session for the response and resuming the Fiber when ready.
     *
     * @param Request $request The request to send
     * @param int     $timeout Maximum time to wait for response (seconds)
     *
     * @return Response<array<string, mixed>>|Error The client's response message
     *
     * @throws RuntimeException If Fiber support is not available
     */
    private function request(Request $request, int $timeout = 120): Response|Error
    {
        $response = \Fiber::suspend([
            'type' => 'request',
            'request' => $request,
            'session_id' => $this->session->getId()->toRfc4122(),
            'timeout' => $timeout,
        ]);

        if (!$response instanceof Response && !$response instanceof Error) {
            throw new RuntimeException('Transport returned an unexpected payload; expected a Response or Error message.');
        }

        return $response;
    }
}
