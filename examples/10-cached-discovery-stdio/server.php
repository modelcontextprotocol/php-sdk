#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

// Example showing how to use discovery caching for improved performance
// This is especially useful in development environments where the server
// is restarted frequently, or in production where discovery happens on every request.

Server::make()
    ->withServerInfo('Cached Discovery Calculator', '1.0.0', 'Calculator with cached discovery for better performance.')
    ->withDiscovery(__DIR__, ['.'])
    ->withCache(new Psr16Cache(new ArrayAdapter())) // Enable discovery caching
    ->build()
    ->connect(new StdioTransport());