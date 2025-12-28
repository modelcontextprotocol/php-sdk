<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp;

use Mcp\Client\Builder;
use Mcp\Client\Configuration;
use Mcp\Client\Protocol;
use Mcp\Client\Transport\TransportInterface;
use Mcp\Exception\ConnectionException;
use Mcp\Exception\RequestException;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\Implementation;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\PromptReference;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\CompletionCompleteRequest;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Request\ListPromptsRequest;
use Mcp\Schema\Request\ListResourcesRequest;
use Mcp\Schema\Request\ListResourceTemplatesRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Request\PingRequest;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Schema\Request\SetLogLevelRequest;
use Mcp\Schema\ResourceReference;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\CompletionCompleteResult;
use Mcp\Schema\Result\GetPromptResult;
use Mcp\Schema\Result\ListPromptsResult;
use Mcp\Schema\Result\ListResourcesResult;
use Mcp\Schema\Result\ListResourceTemplatesResult;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Result\ReadResourceResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
    private ?TransportInterface $transport = null;

    public function __construct(
        private readonly Protocol $protocol,
        private readonly Configuration $config,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
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
     * @throws ConnectionException If connection or initialization fails
     */
    public function connect(TransportInterface $transport): void
    {
        $this->transport = $transport;
        $this->protocol->connect($transport, $this->config);

        $transport->connectAndInitialize($this->config->initTimeout);

        $this->logger->info('Client connected and initialized');
    }

    /**
     * Check if connected and initialized.
     */
    public function isConnected(): bool
    {
        return null !== $this->transport && $this->protocol->getSession()->isInitialized();
    }

    /**
     * Get server information from initialization.
     */
    public function getServerInfo(): ?Implementation
    {
        return $this->protocol->getSession()->getServerInfo();
    }

    /**
     * Get server instructions.
     */
    public function getInstructions(): ?string
    {
        return $this->protocol->getSession()->getInstructions();
    }

    /**
     * Send a ping request to the server.
     */
    public function ping(): void
    {
        $request = new PingRequest();

        $this->sendRequest($request);
    }

    /**
     * List available tools from the server.
     */
    public function listTools(?string $cursor = null): ListToolsResult
    {
        $request = new ListToolsRequest($cursor);

        $response = $this->sendRequest($request);

        return ListToolsResult::fromArray($response->result);
    }

    /**
     * Call a tool on the server.
     *
     * @param string                                                                  $name       Tool name
     * @param array<string, mixed>                                                    $arguments  Tool arguments
     * @param (callable(float $progress, ?float $total, ?string $message): void)|null $onProgress
     *                                                                                            Optional callback for progress updates
     */
    public function callTool(string $name, array $arguments = [], ?callable $onProgress = null): CallToolResult
    {
        $request = new CallToolRequest($name, $arguments);

        $response = $this->sendRequest($request, $onProgress);

        return CallToolResult::fromArray($response->result);
    }

    /**
     * List available resources from the server.
     */
    public function listResources(?string $cursor = null): ListResourcesResult
    {
        $request = new ListResourcesRequest($cursor);

        $response = $this->sendRequest($request);

        return ListResourcesResult::fromArray($response->result);
    }

    /**
     * List available resource templates from the server.
     */
    public function listResourceTemplates(?string $cursor = null): ListResourceTemplatesResult
    {
        $request = new ListResourceTemplatesRequest($cursor);

        $response = $this->sendRequest($request);

        return ListResourceTemplatesResult::fromArray($response->result);
    }

    /**
     * Read a resource by URI.
     *
     * @param string                                                                  $uri        The resource URI
     * @param (callable(float $progress, ?float $total, ?string $message): void)|null $onProgress
     *                                                                                            Optional callback for progress updates
     */
    public function readResource(string $uri, ?callable $onProgress = null): ReadResourceResult
    {
        $request = new ReadResourceRequest($uri);

        $response = $this->sendRequest($request, $onProgress);

        return ReadResourceResult::fromArray($response->result);
    }

    /**
     * List available prompts from the server.
     */
    public function listPrompts(?string $cursor = null): ListPromptsResult
    {
        $request = new ListPromptsRequest($cursor);

        $response = $this->sendRequest($request);

        return ListPromptsResult::fromArray($response->result);
    }

    /**
     * Get a prompt from the server.
     *
     * @param string                                                                  $name       Prompt name
     * @param array<string, string>                                                   $arguments  Prompt arguments
     * @param (callable(float $progress, ?float $total, ?string $message): void)|null $onProgress
     *                                                                                            Optional callback for progress updates
     */
    public function getPrompt(string $name, array $arguments = [], ?callable $onProgress = null): GetPromptResult
    {
        $request = new GetPromptRequest($name, $arguments);

        $response = $this->sendRequest($request, $onProgress);

        return GetPromptResult::fromArray($response->result);
    }

    /**
     * Request completion suggestions for a prompt or resource argument.
     *
     * @param PromptReference|ResourceReference  $ref      The prompt or resource reference
     * @param array{name: string, value: string} $argument The argument to complete
     */
    public function complete(PromptReference|ResourceReference $ref, array $argument): CompletionCompleteResult
    {
        $request = new CompletionCompleteRequest($ref, $argument);

        $response = $this->sendRequest($request);

        return CompletionCompleteResult::fromArray($response->result);
    }

    /**
     * Set the minimum logging level for server log messages.
     */
    public function setLoggingLevel(LoggingLevel $level): void
    {
        $request = new SetLogLevelRequest($level);

        $this->sendRequest($request);
    }

    /**
     * Send a request to the server and wait for response.
     *
     * @param (callable(float $progress, ?float $total, ?string $message): void)|null $onProgress
     *
     * @return Response<mixed>
     *
     * @throws RequestException|ConnectionException
     */
    private function sendRequest(Request $request, ?callable $onProgress = null): Response
    {
        if (!$this->isConnected()) {
            throw new ConnectionException('Client is not connected. Call connect() first.');
        }

        $withProgress = null !== $onProgress;
        $fiber = new \Fiber(fn () => $this->protocol->request($request, $this->config->requestTimeout, $withProgress));
        $response = $this->transport->runRequest($fiber, $onProgress);

        if ($response instanceof Error) {
            throw RequestException::fromError($response);
        }

        return $response;
    }

    /**
     * Disconnect from the server.
     */
    public function disconnect(): void
    {
        if (null !== $this->transport) {
            $this->transport->close();
            $this->transport = null;
            $this->logger->info('Client disconnected');
        }
    }
}
