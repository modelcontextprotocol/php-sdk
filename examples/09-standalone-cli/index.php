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

use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use Symfony\Component\Console as SymfonyConsole;
use Symfony\Component\Console\Output\OutputInterface;

$debug = (bool) ($_SERVER['DEBUG'] ?? false);

// Setup input, output and logger
$output = new SymfonyConsole\Output\ConsoleOutput($debug ? OutputInterface::VERBOSITY_VERY_VERBOSE : OutputInterface::VERBOSITY_NORMAL);
$logger = new SymfonyConsole\Logger\ConsoleLogger($output);

$server = Server::make()
    ->setServerInfo('Standalone CLI', '1.0.0')
    ->setLogger($logger)
    ->setDiscovery(__DIR__, ['.'])
    ->build();

$transport = new StdioTransport(logger: $logger);

$server->connect($transport);

$transport->listen();
