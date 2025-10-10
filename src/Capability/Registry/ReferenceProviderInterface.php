<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Registry;

use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\Page;

/**
 * Interface for providing access to registered MCP elements.
 * Separates the concern of accessing elements from registering them.
 *
 * @author Pavel Buchnev <butschster@gmail.com>
 */
interface ReferenceProviderInterface
{
    /**
     * Gets a tool reference by name.
     */
    public function getTool(string $name): ?ToolReference;

    /**
     * Gets a resource reference by URI (includes template matching if enabled).
     */
    public function getResource(string $uri, bool $includeTemplates = true): ResourceReference|ResourceTemplateReference|null;

    /**
     * Gets a resource template reference by URI template.
     */
    public function getResourceTemplate(string $uriTemplate): ?ResourceTemplateReference;

    /**
     * Gets a prompt reference by name.
     */
    public function getPrompt(string $name): ?PromptReference;

    /**
     * Gets all registered tools.
     */
    public function getTools(?int $limit = null, ?string $cursor = null): Page;

    /**
     * Gets all registered resources.
     */
    public function getResources(?int $limit = null, ?string $cursor = null): Page;

    /**
     * Gets all registered prompts.
     */
    public function getPrompts(?int $limit = null, ?string $cursor = null): Page;

    /**
     * Gets all registered resource templates.
     */
    public function getResourceTemplates(?int $limit = null, ?string $cursor = null): Page;

    /**
     * Checks if any elements (manual or discovered) are currently registered.
     */
    public function hasElements(): bool;

    /**
     * Enables logging message notifications for the MCP server.
     *
     * When enabled, the server will advertise logging capability to clients,
     * indicating that it can emit structured log messages according to the MCP specification.
     */
    public function enableLoggingMessageNotification(): void;

    /**
     * Checks if logging message notification capability is enabled.
     *
     * @return bool True if logging message notification capability is enabled, false otherwise
     */
    public function isLoggingMessageNotificationEnabled(): bool;

    /**
     * Sets the current logging message notification level for the client.
     *
     * This determines which log messages should be sent to the client.
     * Only messages at this level and higher (more severe) will be sent.
     */
    public function setLoggingMessageNotificationLevel(LoggingLevel $level): void;

    /**
     * Gets the current logging message notification level set by the client.
     *
     * @return LoggingLevel|null The current log level, or null if not set
     */
    public function getLoggingMessageNotificationLevel(): ?LoggingLevel;
}
