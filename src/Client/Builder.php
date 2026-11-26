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

use Mcp\Client\Exception\InvalidArgumentException;
use Mcp\Client\Exception\LogicException;
use Mcp\Client\Extension\ClientExtensionInterface;
use Mcp\Client\Handler\Notification\NotificationHandlerInterface;
use Mcp\Client\Handler\Request\RequestHandlerInterface;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Extension\DependentExtensionInterface;
use Mcp\Schema\Extension\ExtensionInterface;
use Mcp\Schema\Implementation;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\MessageFactory;
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
    private ?ProtocolVersion $fallbackProtocolVersion = ProtocolVersion::V2025_11_25;
    private ?ClientCapabilities $capabilities = null;

    /** @var array<string, array<string, mixed>> */
    private array $extensions = [];

    /** @var array<string, string> RPC method to the extension identifier defining it */
    private array $extensionMethods = [];

    /** @var list<class-string<Request>|class-string<Notification>> */
    private array $extensionMessages = [];

    /** @var array<string, list<string>> extension identifier to the identifiers of the extensions it requires */
    private array $extensionRequirements = [];
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
     * Set the protocol version the client prefers, defaults to 2026-07-28; a modern one is probed for first.
     */
    public function setProtocolVersion(ProtocolVersion $protocolVersion): self
    {
        $this->protocolVersion = $protocolVersion;

        return $this;
    }

    /**
     * Set the handshake revision a modern client falls back to, defaults to 2025-11-25; null makes it modern-only.
     */
    public function setFallbackProtocolVersion(?ProtocolVersion $protocolVersion): self
    {
        $this->fallbackProtocolVersion = $protocolVersion;

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
     * An extension also contributes the message classes its methods decode
     * into, a {@see ClientExtensionInterface} the handlers answering them, and a
     * {@see DependentExtensionInterface} the extensions it requires, checked on
     * build.
     *
     * An extension is installed whole or not at all: when it is rejected, none
     * of its contributions are kept.
     *
     * @throws InvalidArgumentException if the identifier is not a valid `_meta` prefix
     * @throws LogicException           if the same extension is enabled more than once, an
     *                                  extension defines a core method, or two enabled
     *                                  extensions define the same RPC method
     */
    public function enableExtension(ExtensionInterface ...$extensions): self
    {
        foreach ($extensions as $extension) {
            $id = (string) $extension->getId();

            if (isset($this->extensions[$id])) {
                throw new LogicException(\sprintf('Extension "%s" is already enabled.', $id));
            }

            /** @var array<string, class-string<Request>|class-string<Notification>> $messages */
            $messages = [];
            foreach ($extension->getMessages() as $message) {
                $method = $message::getMethod();

                if (MessageFactory::isCoreMethod($method)) {
                    throw new LogicException(\sprintf('Extension "%s" cannot define "%s": it is a core MCP method.', $id, $method));
                }

                // The message factory resolves a method to whichever class was
                // registered first, so a second owner would silently never be reached.
                if (isset($this->extensionMethods[$method]) || isset($messages[$method])) {
                    throw new LogicException(\sprintf('Method "%s" is already claimed by extension "%s", so extension "%s" cannot also define it.', $method, $this->extensionMethods[$method] ?? $id, $id));
                }

                $messages[$method] = $message;
            }

            $requestHandlers = [];
            $notificationHandlers = [];
            if ($extension instanceof ClientExtensionInterface) {
                foreach ($extension->getRequestHandlers() as $handler) {
                    $requestHandlers[] = $handler;
                }

                foreach ($extension->getNotificationHandlers() as $handler) {
                    $notificationHandlers[] = $handler;
                }
            }

            $requirements = [];
            if ($extension instanceof DependentExtensionInterface) {
                foreach ($extension->getRequiredExtensions() as $required) {
                    $requirements[] = (string) $required;
                }
            }

            $this->extensions[$id] = $extension->getCapabilities();

            foreach ($messages as $method => $message) {
                $this->extensionMessages[] = $message;
                $this->extensionMethods[$method] = $id;
            }

            array_push($this->requestHandlers, ...$requestHandlers);
            array_push($this->notificationHandlers, ...$notificationHandlers);

            if ([] !== $requirements) {
                $this->extensionRequirements[$id] = $requirements;
            }
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
        foreach ($this->extensionRequirements as $id => $requirements) {
            foreach ($requirements as $required) {
                if (!isset($this->extensions[$required])) {
                    throw new LogicException(\sprintf('Extension "%s" requires extension "%s", which is not enabled.', $id, $required));
                }
            }
        }

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
            protocolVersion: $this->protocolVersion ?? ProtocolVersion::V2026_07_28,
            initTimeout: $this->initTimeout,
            requestTimeout: $this->requestTimeout,
            maxRetries: $this->maxRetries,
            fallbackProtocolVersion: $this->fallbackProtocolVersion,
        );

        $protocol = new Protocol(
            requestHandlers: $this->requestHandlers,
            notificationHandlers: $this->notificationHandlers,
            messageFactory: MessageFactory::make(additional: $this->extensionMessages),
            logger: $logger,
        );

        return new Client($protocol, $config, $logger);
    }
}
