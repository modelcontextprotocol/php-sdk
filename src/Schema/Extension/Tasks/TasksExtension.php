<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Extension\Tasks;

use Mcp\Schema\Extension\ExtensionIdentifier;
use Mcp\Schema\Extension\ExtensionInterface;

/**
 * @see https://tasks.extensions.modelcontextprotocol.io/specification/2026-07-28/tasks
 *
 * @author OpenAI
 */
final class TasksExtension implements ExtensionInterface
{
    public const EXTENSION_ID = 'io.modelcontextprotocol/tasks';

    public function getId(): ExtensionIdentifier
    {
        return new ExtensionIdentifier(self::EXTENSION_ID);
    }

    public function getCapabilities(): array
    {
        return [];
    }

    public function getMessages(): array
    {
        return [GetTaskRequest::class, UpdateTaskRequest::class, CancelTaskRequest::class];
    }
}
