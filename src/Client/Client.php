<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client;

use Mcp\Client\Handler\InternalProgressHandler;
use Mcp\Client\Session\ClientSession;
use Mcp\Client\Session\ClientSessionInterface;
use Mcp\Client\Transport\ClientTransportInterface;
use Mcp\Exception\ConnectionException;
use Mcp\Exception\RequestException;
use Mcp\Handler\NotificationHandlerInterface;
use Mcp\Handler\RequestHandlerInterface;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Request\ListPromptsRequest;
use Mcp\Schema\Request\ListResourcesRequest;
use Mcp\Schema\Request\ListResourceTemplatesRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Request\PingRequest;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\GetPromptResult;
use Mcp\Schema\Result\ListPromptsResult;
use Mcp\Schema\Result\ListResourcesResult;
use Mcp\Schema\Result\ListResourceTemplatesResult;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Result\ReadResourceResult;
use Psr\Log\LoggerInterface;

/**
 * Main MCP Client facade.
 *
 * Provides a synchronous API for communicating with MCP servers.
 * All blocking operations are delegated to the transport.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class Client
{
    private Protocol $protocol;
    private ClientSessionInterface $session;
    private ?ClientTransportInterface $transport = null;
    private int $progressTokenCounter = 0;

    /**
     * @param NotificationHandlerInterface[] $notificationHandlers
     * @param RequestHandlerInterface[]      $requestHandlers
     */
    public function __construct(
        private readonly Configuration $config,
        array $notificationHandlers = [],
        array $requestHandlers = [],
        ?LoggerInterface $logger = null,
    ) {
        $this->session = new ClientSession();

        // Auto-register internal progress handler to dispatch per-request callbacks
        $allNotificationHandlers = [
            new InternalProgressHandler($this->session),
            ...$notificationHandlers,
        ];

        $this->protocol = new Protocol(
            $this->session,
            $config,
            $allNotificationHandlers,
            $requestHandlers,
            null,
            $logger
        );
    }

    /**
     * Create a new client builder for fluent configuration.
     */
    public static function builder(): Builder
    {
        return new Builder();
    }

    /**
     * Connect to an MCP server using the provided transport.
     *
     * This method blocks until initialization completes or times out.
     * The transport handles all blocking operations internally.
     *
     * @throws ConnectionException If connection or initialization fails
     */
    public function connect(ClientTransportInterface $transport): void
    {
        $this->transport = $transport;
        $this->protocol->connect($transport);

        $transport->connectAndInitialize($this->config->initTimeout);
    }

    /**
     * Check if connected and initialized.
     */
    public function isConnected(): bool
    {
        return null !== $this->transport && $this->protocol->getSession()->isInitialized();
    }

    /**
     * Ping the server.
     */
    public function ping(): void
    {
        $this->ensureConnected();
        $this->doRequest(new PingRequest());
    }

    /**
     * List available tools from the server.
     */
    public function listTools(?string $cursor = null): ListToolsResult
    {
        $this->ensureConnected();

        return $this->doRequest(new ListToolsRequest($cursor), ListToolsResult::class);
    }

    /**
     * Call a tool on the server.
     *
     * @param string               $name       Tool name
     * @param array<string, mixed> $arguments  Tool arguments
     * @param (callable(float $progress, ?float $total, ?string $message): void)|null $onProgress
     *        Optional callback for progress updates. If provided, a progress token
     *        is automatically generated and attached to the request.
     */
    public function callTool(
        string $name,
        array $arguments = [],
        ?callable $onProgress = null,
    ): CallToolResult {
        $this->ensureConnected();

        $request = new CallToolRequest($name, $arguments);

        return $this->doRequest($request, CallToolResult::class, $onProgress);
    }

    /**
     * List available resources.
     */
    public function listResources(?string $cursor = null): ListResourcesResult
    {
        $this->ensureConnected();

        return $this->doRequest(new ListResourcesRequest($cursor), ListResourcesResult::class);
    }

    /**
     * List available resource templates.
     */
    public function listResourceTemplates(?string $cursor = null): ListResourceTemplatesResult
    {
        $this->ensureConnected();

        return $this->doRequest(new ListResourceTemplatesRequest($cursor), ListResourceTemplatesResult::class);
    }

    /**
     * Read a resource by URI.
     *
     * @param string $uri The resource URI
     * @param (callable(float $progress, ?float $total, ?string $message): void)|null $onProgress
     *        Optional callback for progress updates.
     */
    public function readResource(string $uri, ?callable $onProgress = null): ReadResourceResult
    {
        $this->ensureConnected();

        $request = new ReadResourceRequest($uri);

        return $this->doRequest($request, ReadResourceResult::class, $onProgress);
    }

    /**
     * List available prompts.
     */
    public function listPrompts(?string $cursor = null): ListPromptsResult
    {
        $this->ensureConnected();

        return $this->doRequest(new ListPromptsRequest($cursor), ListPromptsResult::class);
    }

    /**
     * Get a prompt by name.
     *
     * @param string                $name      Prompt name
     * @param array<string, string> $arguments Prompt arguments
     * @param (callable(float $progress, ?float $total, ?string $message): void)|null $onProgress
     *        Optional callback for progress updates.
     */
    public function getPrompt(
        string $name,
        array $arguments = [],
        ?callable $onProgress = null,
    ): GetPromptResult {
        $this->ensureConnected();

        $request = new GetPromptRequest($name, $arguments);

        return $this->doRequest($request, GetPromptResult::class, $onProgress);
    }

    /**
     * Get the server info received during initialization.
     *
     * @return array<string, mixed>|null
     */
    public function getServerInfo(): ?array
    {
        return $this->protocol->getSession()->getServerInfo();
    }

    /**
     * Disconnect from the server.
     */
    public function disconnect(): void
    {
        $this->transport?->close();
        $this->transport = null;
    }

    /**
     * Execute a request and return the typed result.
     *
     * @template T
     *
     * @param class-string<T>|null $resultClass
     * @param (callable(float $progress, ?float $total, ?string $message): void)|null $onProgress
     *
     * @return T|Response<array<string, mixed>>
     *
     * @throws RequestException
     */
    private function doRequest(object $request, ?string $resultClass = null, ?callable $onProgress = null): mixed
    {
        if (null !== $onProgress && $request instanceof Request) {
            $progressToken = $this->generateProgressToken();
            $request = $request->withMeta(['progressToken' => $progressToken]);
        }

        $fiber = new \Fiber(fn() => $this->protocol->request($request, $this->config->requestTimeout));

        $response = $this->transport->runRequest($fiber, $onProgress);

        if ($response instanceof Error) {
            throw RequestException::fromError($response);
        }

        if (null === $resultClass) {
            return $response;
        }

        return $resultClass::fromArray($response->result);
    }

    /**
     * Generate a unique progress token for a request.
     */
    private function generateProgressToken(): string
    {
        return 'prog-' . (++$this->progressTokenCounter);
    }

    private function ensureConnected(): void
    {
        if (!$this->isConnected()) {
            throw new ConnectionException('Client is not connected. Call connect() first.');
        }
    }
}
