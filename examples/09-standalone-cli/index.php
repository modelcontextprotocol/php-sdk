<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

use App\Builder;
use Mcp\JsonRpc\Handler;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require __DIR__.'/vendor/autoload.php';

use Symfony\Component\Console as SymfonyConsole;
use Symfony\Component\Console\Output\OutputInterface;

$debug = (bool) ($_SERVER['DEBUG'] ?? false);

// Setup input, output and logger
$output = new SymfonyConsole\Output\ConsoleOutput($debug ? OutputInterface::VERBOSITY_VERY_VERBOSE : OutputInterface::VERBOSITY_NORMAL);
$logger = new SymfonyConsole\Logger\ConsoleLogger($output);

// Configure the JsonRpcHandler and build the functionality
$jsonRpcHandler = new Handler(
    MessageFactory::make(),
    Builder::buildMethodHandlers(),
    $logger
);

// Set up the server
$sever = new Server($jsonRpcHandler, $logger);

// Create the transport layer using Stdio
$transport = new StdioTransport(logger: $logger);

// Start our application
$sever->connect($transport);
