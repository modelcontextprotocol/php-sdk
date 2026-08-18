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
use Mcp\Exception\LogicException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Extension\ExtensionInterface;
use Mcp\Schema\Implementation;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Fluent builder for creating Client instances.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class Builder
{
    private string $name = 'mcp-php-client';
    private string $version = '1.0.0';
    private ?string $description = null;
    private ?string $title = null;
    private ?ProtocolVersion $protocolVersion = null;
    private ?ClientCapabilities $capabilities = null;

    /** @var array<string, array<string, mixed>> */
    private array $extensions = [];
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
     *
     * @param ?string $title Display name for UI and end-user contexts. Falls back to $name when absent.
     */
    public function setClientInfo(string $name, string $version, ?string $description = null, ?string $title = null): self
    {
        $this->name = $name;
        $this->version = $version;
        $this->description = $description;
        $this->title = $title;

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
     * Enable one or more MCP protocol extensions, announced to the server under
     * `capabilities.extensions` in the initialize request.
     *
     * @throws LogicException if the same extension is enabled more than once
     */
    public function enableExtension(ExtensionInterface ...$extensions): self
    {
        foreach ($extensions as $extension) {
            $id = $extension->getId();

            if (isset($this->extensions[$id])) {
                throw new LogicException(\sprintf('Extension "%s" is already enabled.', $id));
            }

            $this->extensions[$id] = $extension->getCapabilities();
        }

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
     * Set the number of times a failed connection attempt is retried.
     *
     * Counts retries, not attempts: 3 means one initial attempt plus up to three
     * retries. Pass 0 to fail on the first failure. Only applies to
     * {@see Client::connect()}; individual requests are never retried.
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
            title: $this->title,
        );

        $capabilities = $this->capabilities ?? new ClientCapabilities();

        // Extensions enabled via enableExtension() are folded into caller-supplied
        // capabilities too, so setCapabilities() does not silently drop them.
        if ([] !== $this->extensions) {
            $capabilities = $capabilities->withExtensions($this->extensions);
        }

        $config = new Configuration(
            clientInfo: $clientInfo,
            capabilities: $capabilities,
            protocolVersion: $this->protocolVersion ?? ProtocolVersion::V2025_11_25,
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
