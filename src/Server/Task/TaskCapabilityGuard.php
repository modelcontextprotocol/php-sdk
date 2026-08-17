<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Task;

use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Server\Session\SessionInterface;

/**
 * Keeps the `tasks/*` surface behind the negotiation that opens it.
 *
 * A client that never declared the extension is not merely uninterested — it
 * has no task of its own to ask about, so the answer names the missing
 * declaration rather than reporting a bad `taskId`.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class TaskCapabilityGuard
{
    /**
     * The refusal to return, or null when the client declared the extension.
     */
    public static function refuse(Request $request, SessionInterface $session): ?Error
    {
        if (self::declared($session)) {
            return null;
        }

        return Error::forMissingRequiredClientCapability(
            \sprintf('The "%s" methods need the extension the client did not declare.', TasksExtension::ID),
            self::required(),
            $request->getId(),
        );
    }

    /**
     * Whether the client declared the extension during `initialize`.
     */
    public static function declared(SessionInterface $session): bool
    {
        $capabilities = (array) $session->get('client_capabilities', []);
        $extensions = $capabilities['extensions'] ?? null;

        return TasksExtension::declaredBy(null === $extensions ? null : (array) $extensions);
    }

    private static function required(): ClientCapabilities
    {
        return new ClientCapabilities(roots: false, extensions: [TasksExtension::ID => new \stdClass()]);
    }
}
