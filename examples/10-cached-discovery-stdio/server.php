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

require_once __DIR__ . '/../bootstrap.php';

use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

Server::make()
    ->withServerInfo('Cached Discovery Calculator', '1.0.0', 'Calculator with cached discovery for better performance.')
    ->withDiscovery(__DIR__, ['.'])
    ->withLogger(logger())
    ->withCache(new Psr16Cache(new ArrayAdapter()))
    ->build()
    ->connect(new StdioTransport());
