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

use Mcp\Capability\Registry;
use Mcp\Capability\RegistryInterface;
use Mcp\Event\Dispatcher;
use Mcp\Event\ListenerProvider;
use Mcp\Example\Server\ChangeEvents\ListChangingHandlers;
use Mcp\Server;

logger()->info('Starting MCP Change Events Server...');

$listenerProvider = new ListenerProvider();
$dispatcher = new Dispatcher($listenerProvider);
$registry = new Registry($dispatcher, logger());
$container = container();
$container->set(RegistryInterface::class, $registry);

$server = Server::builder()
    ->setServerInfo('Server with Changing Lists', '1.0.0')
    ->setLogger(logger())
    ->setContainer($container)
    ->setRegistry($registry)
    ->setEventDispatcher($dispatcher)
    ->setEventListenerProvider($listenerProvider)
    ->addTool([ListChangingHandlers::class, 'addPrompt'], 'add_prompt', 'Tool that adds a new prompt to the registry with the given name and content.')
    ->addTool([ListChangingHandlers::class, 'addResource'], 'add_resource', 'Tool that adds a new resource to the registry with the given name and URL.')
    ->addTool([ListChangingHandlers::class, 'addTool'], 'add_tool', 'Tool that adds a new tool to the registry with the given name.')
    ->build();

$result = $server->run(transport());

logger()->info('Server listener stopped gracefully.', ['result' => $result]);

shutdown($result);
