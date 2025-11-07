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

use Mcp\Schema\Icon;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;

$server = Server::builder()
    ->setServerInfo(
        'Schema Showcase',
        '1.0.0',
        'A showcase server demonstrating MCP schema capabilities.',
        [new Icon('https://www.php.net/images/logos/php-logo-white.svg', 'image/svg+xml', ['any'])],
        'https://github.com/modelcontextprotocol/php-sdk',
    )
    ->setContainer(container())
    ->setLogger(logger())
    ->setSession(new FileSessionStore(__DIR__.'/sessions'))
    ->setDiscovery(__DIR__)
    ->build();

$response = $server->run(transport());

shutdown($response);
