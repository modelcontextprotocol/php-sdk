#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * MCP Elicitation Example Server
 *
 * This example demonstrates the elicitation feature which allows servers to
 * request additional information from users during tool execution.
 *
 * Elicitation enables interactive workflows where the server can:
 * - Ask for user preferences or choices
 * - Collect form data with validated fields
 * - Request confirmation before actions
 *
 * The server provides three example tools:
 * 1. book_restaurant - Multi-field form with number, date, and enum fields
 * 2. confirm_action - Simple boolean confirmation dialog
 * 3. collect_feedback - Rating and comments form with optional fields
 *
 * IMPORTANT: Elicitation requires:
 * - A session store (FileSessionStore is used here)
 * - Client support for elicitation (check client capabilities)
 *
 * Usage:
 *   php server.php
 *
 * The server will start in stdio mode and wait for MCP client connections.
 */

require_once dirname(__DIR__).'/bootstrap.php';
chdir(__DIR__);

use Mcp\Schema\ServerCapabilities;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;

$server = Server::builder()
    ->setServerInfo('Elicitation Demo', '1.0.0')
    ->setLogger(logger())
    ->setContainer(container())
    // Session store is REQUIRED for server-to-client requests like elicitation
    ->setSession(new FileSessionStore(__DIR__.'/sessions'))
    ->setCapabilities(new ServerCapabilities(logging: true, tools: true))
    // Auto-discover tools from ElicitationHandlers class
    ->setDiscovery(__DIR__)
    ->build();

$result = $server->run(transport());

shutdown($result);
