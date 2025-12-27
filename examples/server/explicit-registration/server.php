#!/usr/bin/env php
<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require_once dirname(__DIR__).'/bootstrap.php';
chdir(__DIR__);

use Mcp\Example\Server\ExplicitRegistration\SimpleHandlers;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server;

logger()->info('Starting MCP Manual Registration Server...');

$server = Server::builder()
    ->setServerInfo('Explicit Registration Server', '1.0.0')
    ->setLogger(logger())
    ->setContainer(container())
    ->addTool([SimpleHandlers::class, 'echoText'], 'echo_text')
    ->addResource([SimpleHandlers::class, 'getAppVersion'], 'app://version', 'application_version', mimeType: 'text/plain')
    ->addPrompt([SimpleHandlers::class, 'greetingPrompt'], 'personalized_greeting')
    ->addResourceTemplate([SimpleHandlers::class, 'getItemDetails'], 'item://{itemId}/details', 'get_item_details', mimeType: 'application/json')
    ->setCapabilities(new ServerCapabilities(
        tools: true,
        toolsListChanged: false,
        resources: true,
        resourcesSubscribe: false,
        resourcesListChanged: false,
        prompts: true,
        promptsListChanged: false,
        logging: false,
        completions: false,
    ))
    ->build();

$result = $server->run(transport());

logger()->info('Server listener stopped gracefully.', ['result' => $result]);

shutdown($result);
