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
 * MCP Elicitation Example Server.
 *
 * Demonstrates server-to-client elicitation for interactive user input during tool execution.
 * See docs/examples.md for detailed documentation and usage examples.
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
    ->setSession(new FileSessionStore(__DIR__.'/sessions'))
    ->setCapabilities(new ServerCapabilities(logging: true, tools: true))
    ->setDiscovery(__DIR__)
    ->build();

$result = $server->run(transport());

shutdown($result);
