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

use Mcp\Handler\NotificationHandlerInterface;
use Mcp\Handler\RequestHandlerInterface;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Implementation;
use Psr\Log\LoggerInterface;

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
    private ?string $protocolVersion = null;
    private ?ClientCapabilities $capabilities = null;
    private int $initTimeout = 30;
    private int $requestTimeout = 120;
    private int $maxRetries = 3;
    private ?LoggerInterface $logger = null;

    /** @var NotificationHandlerInterface[] */
    private array $notificationHandlers = [];

    /** @var RequestHandlerInterface[] */
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
    public function setProtocolVersion(string $version): self
    {
        $this->protocolVersion = $version;

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
        $clientInfo = new Implementation(
            $this->name,
            $this->version,
            $this->description,
        );

        $config = new Configuration(
            clientInfo: $clientInfo,
            capabilities: $this->capabilities ?? new ClientCapabilities(),
            protocolVersion: $this->protocolVersion ?? '2025-06-18',
            initTimeout: $this->initTimeout,
            requestTimeout: $this->requestTimeout,
            maxRetries: $this->maxRetries,
        );

        return new Client($config, $this->notificationHandlers, $this->requestHandlers, $this->logger);
    }
}
