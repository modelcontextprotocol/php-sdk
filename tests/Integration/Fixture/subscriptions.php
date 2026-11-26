<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// Server for SubscriptionsTest under `php -S`: subscription streams read a bus the test publishes to, kept in MCP_INTEGRATION_BUS.

use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server\Server;
use Mcp\Server\Subscription\Psr16NotificationBus;
use Mcp\Server\Transport\StreamableHttpTransport;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

$server = Server::builder()
    ->setServerInfo('integration-server', '1.0.0')
    ->setCapabilities(new ServerCapabilities(tools: false, resources: true, resourcesSubscribe: true, prompts: false))
    ->setNotificationBus(new Psr16NotificationBus(new Psr16Cache(new FilesystemAdapter('bus', 0, (string) getenv('MCP_INTEGRATION_BUS')))))
    ->setSubscriptionLifetime((float) (getenv('MCP_INTEGRATION_LIFETIME') ?: 10))
    ->build();

(new SapiEmitter())->emit($server->run(new StreamableHttpTransport((new Psr17Factory())->createServerRequestFromGlobals())));
