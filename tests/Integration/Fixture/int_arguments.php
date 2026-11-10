<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Mcp\Server\Capability\Attribute\Schema;
use Mcp\Server\Server;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Tests\Unit\Fixtures\Enum\BackedIntEnum;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

Server::builder()
    ->setServerInfo('int-arguments', '1.0.0')
    ->addTool(static fn (#[Schema(minimum: 1)] int $qty): string => 'got '.$qty, name: 'at_least_one')
    ->addTool(static fn (#[Schema(maximum: 100)] int $qty): string => 'got '.$qty, name: 'at_most_hundred')
    ->addTool(static fn (?int $qty = null): string => 'got '.var_export($qty, true), name: 'nullable')
    ->addTool(static fn (int ...$qty): string => 'got '.implode(',', $qty), name: 'variadic')
    ->addTool(static fn (int $qty): string => 'got '.$qty, name: 'permissive', inputSchema: ['type' => 'object'])
    ->addPrompt(static fn (int $qty): array => [['role' => 'user', 'content' => 'got '.$qty]], name: 'order')
    ->addPrompt(static fn (BackedIntEnum $level): array => [['role' => 'user', 'content' => 'got '.$level->value]], name: 'level')
    ->addResourceTemplate(static fn (int $id): string => 'got '.$id, 'item://{id}', 'item')
    ->addResourceTemplate(static fn (BackedIntEnum $level): string => 'got '.$level->value, 'level://{level}', 'level')
    ->build()
    ->run(new StdioTransport());
