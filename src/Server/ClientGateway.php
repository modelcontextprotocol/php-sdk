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
use Mcp\Schema\Elicitation\ElicitationSchema;
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
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Request\ListRootsRequest;
use Mcp\Schema\Result\CreateSamplingMessageResult;
use Mcp\Schema\Result\ElicitResult;
use Mcp\Schema\Result\ListRootsResult;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolChoice;
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
 *     $result = $client->sample($text);
 *
 *     return $result->content->text;
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
 *     tools?: Tool[],
 *     toolChoice?: ToolChoice,
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
     *
     * @deprecated since protocol revision 2026-07-28 (SEP-2577), earliest removal 2027-07-28.
     *             Log to stderr (stdio) or use OpenTelemetry instead.
     */
    public function log(LoggingLevel $level, mixed $data, ?string $logger = null): void
    {
        trigger_deprecation('mcp/sdk', '0.8', 'MCP logging is deprecated since protocol revision 2026-07-28 (SEP-2577); log to stderr or use OpenTelemetry instead.');

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
     *                                                                                  Context values other than `none` require the client's
     *                                                                                  sampling.context capability; tools and toolChoice require
     *                                                                                  the client's sampling.tools capability.
     *
     * @return CreateSamplingMessageResult The sampling response
     *
     * @throws ClientException if the client request results in an error message
     *
     * @deprecated since protocol revision 2026-07-28 (SEP-2577), earliest removal 2027-07-28.
     *             Integrate with an LLM provider's API directly instead.
     */
    public function sample(array|Content|string $message, int $maxTokens = 1000, int $timeout = 120, array $options = []): CreateSamplingMessageResult
    {
        trigger_deprecation('mcp/sdk', '0.8', 'MCP sampling is deprecated since protocol revision 2026-07-28 (SEP-2577); integrate with an LLM provider\'s API directly instead.');

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
            tools: $options['tools'] ?? null,
            toolChoice: $options['toolChoice'] ?? null,
        );

        // Fail here rather than letting the client reject the request with -32602.
        $request->validateToolFlow();

        $response = $this->request($request, $timeout);

        if ($response instanceof Error) {
            throw new ClientException($response);
        }

        return CreateSamplingMessageResult::fromArray($response->result);
    }

    /**
     * Convenience method for form-mode elicitation requests.
     *
     * Requests additional information from the user via the client. The user can
     * accept (providing the requested data), decline, or cancel the request.
     *
     * @param string            $message         A human-readable message describing what information is needed
     * @param ElicitationSchema $requestedSchema The schema defining the fields to elicit from the user
     * @param int               $timeout         The timeout in seconds
     *
     * @return ElicitResult The elicitation response containing the user's action and any provided content
     *
     * @throws ClientException if the client request results in an error message
     */
    public function elicit(string $message, ElicitationSchema $requestedSchema, int $timeout = 120): ElicitResult
    {
        return $this->sendElicitation(ElicitRequest::forForm($message, $requestedSchema), $timeout);
    }

    /**
     * Convenience method for url-mode elicitation requests.
     *
     * Sends the user to $url to complete the interaction out of band — an OAuth
     * consent screen, a checkout, a form hosted elsewhere. The result carries only
     * the user's action; unlike form mode there is no content to read back, so
     * whatever the user did there has to be picked up through the URL's own channel.
     *
     * @throws ClientException          if the client request results in an error message
     * @throws InvalidArgumentException if the client did not declare url-mode elicitation
     */
    public function elicitUrl(string $message, string $url, int $timeout = 120): ElicitResult
    {
        // URL mode only exists from 2025-11-25 on, and only for clients declaring it
        if (!$this->supportsElicitationUrl()) {
            throw new InvalidArgumentException('The client did not declare the "elicitation.url" capability, so it cannot be sent a url-mode elicitation.');
        }

        return $this->sendElicitation(ElicitRequest::forUrl($message, $url), $timeout);
    }

    /**
     * Request the list of filesystem roots exposed by the client.
     *
     * Roots are the client's "workspace folders" — the directories or files the
     * server is allowed to operate on. The client answers the roots/list request
     * with a list of file:// URIs.
     *
     * @param int $timeout The timeout in seconds
     *
     * @return ListRootsResult The roots exposed by the client
     *
     * @throws ClientException if the client request results in an error message
     *
     * @deprecated since protocol revision 2026-07-28 (SEP-2577), earliest removal 2027-07-28.
     *             Pass directories or files through tool arguments, resource URIs or server
     *             configuration instead.
     */
    public function listRoots(int $timeout = 120): ListRootsResult
    {
        trigger_deprecation('mcp/sdk', '0.8', 'MCP roots are deprecated since protocol revision 2026-07-28 (SEP-2577); pass directories through tool arguments or resource URIs instead.');

        $request = new ListRootsRequest();

        $response = $this->request($request, $timeout);

        if ($response instanceof Error) {
            throw new ClientException($response);
        }

        return ListRootsResult::fromArray($response->result);
    }

    /**
     * Check if the connected client supports roots.
     *
     * Roots allow servers to ask the client for the set of directories or files
     * it is permitted to operate on. This method checks the client's advertised
     * capabilities to determine if roots/list requests are supported.
     *
     * @return bool True if the client supports roots, false otherwise
     */
    public function supportsRoots(): bool
    {
        $capabilities = (array) $this->session->get('client_capabilities', []);

        // MCP spec: capability presence indicates support (value is typically {} or [])
        return \array_key_exists('roots', $capabilities);
    }

    /**
     * Check if the connected client supports elicitation.
     *
     * Elicitation allows servers to request additional information from users
     * during tool execution. This method checks the client's advertised capabilities
     * to determine if elicitation/create requests are supported.
     *
     * @return bool True if the client supports elicitation, false otherwise
     */
    public function supportsElicitation(): bool
    {
        $capabilities = (array) $this->session->get('client_capabilities', []);

        // MCP spec: capability presence indicates support (value is typically {} or [])
        return \array_key_exists('elicitation', $capabilities);
    }

    /**
     * Check if the connected client supports sampling.
     *
     * Sampling lets a server borrow the client's model during tool execution.
     * This method checks the client's advertised capabilities to determine if
     * sampling/createMessage requests are supported.
     *
     * @return bool True if the client supports sampling, false otherwise
     */
    public function supportsSampling(): bool
    {
        $capabilities = (array) $this->session->get('client_capabilities', []);

        // MCP spec: capability presence indicates support (value is typically {} or [])
        return \array_key_exists('sampling', $capabilities);
    }

    /**
     * Check if the connected client supports tools during sampling.
     *
     * Per the spec a server MUST NOT put `tools` or `toolChoice` on a
     * `sampling/createMessage` request unless the client advertised
     * `sampling.tools`, so check this before passing either option to
     * {@see self::sample()}.
     *
     * @return bool True if the client supports tool-enabled sampling, false otherwise
     */
    public function supportsSamplingTools(): bool
    {
        return $this->hasSubCapability('sampling', 'tools');
    }

    /**
     * Check if the connected client supports context inclusion during sampling.
     *
     * The `includeContext` values other than `none` are soft-deprecated and should
     * only be sent when the client advertised `sampling.context`.
     *
     * @return bool True if the client supports sampling context, false otherwise
     */
    public function supportsSamplingContext(): bool
    {
        return $this->hasSubCapability('sampling', 'context');
    }

    /**
     * Check if the connected client supports url-mode elicitation.
     *
     * An `elicitation` capability naming no mode declares form mode — the only shape
     * that existed before URL elicitation — so url mode has to be named explicitly.
     *
     * @return bool True if the client supports url-mode elicitation, false otherwise
     */
    public function supportsElicitationUrl(): bool
    {
        return $this->hasSubCapability('elicitation', 'url');
    }

    /**
     * Check if the connected client negotiated the given protocol extension.
     *
     * Extensions are advertised under `capabilities.extensions` keyed by their
     * reverse-DNS id, e.g. `McpApps::EXTENSION_ID` — a server offering MCP Apps
     * should check this before pointing a tool at a `ui://` resource, and fall back
     * to a text-only result otherwise.
     *
     * @return bool True if the client advertised the extension, false otherwise
     */
    public function supportsExtension(string $id): bool
    {
        return $this->hasSubCapability('extensions', $id);
    }

    /**
     * Sub-capabilities are declared by the presence of a (possibly empty) object, so
     * only the key matters — not whatever it holds. The value arrives as an object on
     * a live session and as an array once the session has round-tripped through JSON,
     * hence both shapes.
     */
    private function hasSubCapability(string $capability, string $name): bool
    {
        $capabilities = (array) $this->session->get('client_capabilities', []);
        $declared = $capabilities[$capability] ?? null;

        if (\is_array($declared)) {
            return \array_key_exists($name, $declared);
        }

        return \is_object($declared) && property_exists($declared, $name);
    }

    /**
     * @throws ClientException if the client request results in an error message
     */
    private function sendElicitation(ElicitRequest $request, int $timeout): ElicitResult
    {
        $response = $this->request($request, $timeout);

        if ($response instanceof Error) {
            throw new ClientException($response);
        }

        return ElicitResult::fromArray($response->result, $request->mode);
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
