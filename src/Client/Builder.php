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

use Mcp\Client;
use Mcp\Client\Handler\Notification\NotificationHandlerInterface;
use Mcp\Client\Handler\Request\RequestHandlerInterface;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Implementation;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Fluent builder for creating Client instances.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class Builder
{
    private string $name = 'mcp-php-client';
    private string $version = '1.0.0';
    private ?string $description = null;
    private ?ProtocolVersion $protocolVersion = null;
    private ?ClientCapabilities $capabilities = null;
    private int $initTimeout = 30;
    private int $requestTimeout = 120;
    private int $maxRetries = 3;
    private ?LoggerInterface $logger = null;

    /** @var NotificationHandlerInterface[] */
    private array $notificationHandlers = [];

    /** @var RequestHandlerInterface<mixed>[] */
    private array $requestHandlers = [];

    /**
     * Set the client name and version.
     */
    public function setClientInfo(string $name, string $version, ?string $description = null): self
    {
        $this->name = $name;
        $this->version = $version;
        $this->description = $description;

        return $this;
    }

    /**
     * Set the protocol version to use.
     */
    public function setProtocolVersion(ProtocolVersion $protocolVersion): self
    {
        $this->protocolVersion = $protocolVersion;

        return $this;
    }

    /**
     * Set client capabilities.
     */
    public function setCapabilities(ClientCapabilities $capabilities): self
    {
        $this->capabilities = $capabilities;

        return $this;
    }

    /**
     * Set initialization timeout in seconds.
     */
    public function setInitTimeout(int $seconds): self
    {
        $this->initTimeout = $seconds;

        return $this;
    }

    /**
     * Set request timeout in seconds.
     */
    public function setRequestTimeout(int $seconds): self
    {
        $this->requestTimeout = $seconds;

        return $this;
    }

    /**
     * Set maximum retry attempts for failed connections.
     */
    public function setMaxRetries(int $retries): self
    {
        $this->maxRetries = $retries;

        return $this;
    }

    /**
     * Set the logger.
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Add a notification handler for server notifications.
     */
    public function addNotificationHandler(NotificationHandlerInterface $handler): self
    {
        $this->notificationHandlers[] = $handler;

        return $this;
    }

    /**
     * Add a request handler for server requests (e.g., sampling).
     *
     * @param RequestHandlerInterface<mixed> $handler
     */
    public function addRequestHandler(RequestHandlerInterface $handler): self
    {
        $this->requestHandlers[] = $handler;

        return $this;
    }

    /**
     * Build the client instance.
     */
    public function build(): Client
    {
        $logger = $this->logger ?? new NullLogger();

        $clientInfo = new Implementation(
            $this->name,
            $this->version,
            $this->description,
        );

        $config = new Configuration(
            clientInfo: $clientInfo,
            capabilities: $this->capabilities ?? new ClientCapabilities(),
            protocolVersion: $this->protocolVersion ?? ProtocolVersion::V2025_06_18,
            initTimeout: $this->initTimeout,
            requestTimeout: $this->requestTimeout,
            maxRetries: $this->maxRetries,
        );

        $protocol = new Protocol(
            requestHandlers: $this->requestHandlers,
            notificationHandlers: $this->notificationHandlers,
            logger: $logger,
        );

        return new Client($protocol, $config, $logger);
    }
}
