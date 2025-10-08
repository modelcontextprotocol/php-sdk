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

use Mcp\Example\StdioToolSampling\SamplingTool;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

logger()->info('Starting MCP Server with Sampling ...');

$server = Server::builder()
    ->setServerInfo('Sampling Server', '1.0.0')
    ->setLogger(logger())
    ->setContainer(container())
    ->addTool([SamplingTool::class, 'trySampling'], 'try_sampling')
    ->build();

$transport = new StdioTransport(logger: logger());

$server->connect($transport);

$transport->listen();

logger()->info('Server listener stopped gracefully.');
