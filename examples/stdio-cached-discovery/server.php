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

require_once dirname(__DIR__).'/bootstrap.php';
chdir(__DIR__);

use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

logger()->info('Starting MCP Cached Discovery Calculator Server...');

$server = Server::builder()
    ->setServerInfo('Cached Discovery Calculator', '1.0.0', 'Calculator with cached discovery for better performance.')
    ->setContainer(container())
    ->setLogger(logger())
    ->setDiscovery(__DIR__, ['.'], [], new Psr16Cache(new ArrayAdapter()))
    ->build();

$transport = new StdioTransport(logger: logger());

$result = $server->run($transport);

logger()->info('Server listener stopped gracefully.', ['result' => $result]);

exit((int) $result);
