<?php

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
$jsonRpcHandler = new Mcp\JsonRpc\Handler(
    Mcp\JsonRpc\MessageFactory::make(),
    App\Builder::buildMethodHandlers(),
    $logger
);

$notificationPublisher = new Mcp\Server\NotificationPublisher();

// Set up the server
$sever = new Mcp\Server($jsonRpcHandler, $notificationPublisher, $logger);

// Create the transport layer using Stdio
$transport = new Mcp\Server\Transport\StdioTransport(logger: $logger);

// Start our application
$sever->connect($transport);
