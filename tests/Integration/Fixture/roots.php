<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Server for {@see \Mcp\Tests\Integration\RootsTest}.
 */

use Mcp\Schema\Request\ListRootsRequest;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server;
use Mcp\Server\RequestContext;
use Mcp\Server\Transport\StdioTransport;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

Server::builder()
    ->setServerInfo('integration-server', '1.0.0')
    ->addTool(
        static function (RequestContext $context): string {
            $gateway = $context->getClientGateway();

            if (!$gateway->supportsRoots()) {
                return 'unsupported';
            }

            $described = [];
            foreach ($gateway->listRoots()->roots as $root) {
                $described[] = sprintf('%s (%s)', $root->uri, $root->name ?? '-');
            }

            return implode(', ', $described);
        },
        name: 'inspect_roots',
        description: 'Reports the workspace roots the client exposes.',
    )
    ->addTool(
        static function (RequestContext $context): string|InputRequiredResult {
            $result = $context->getInputContext()?->rootsResult('roots');

            if (null === $result) {
                return new InputRequiredResult(['roots' => new ListRootsRequest()]);
            }

            $described = [];
            foreach ($result->roots as $root) {
                $described[] = sprintf('%s (%s)', $root->uri, $root->name ?? '-');
            }

            return implode(', ', $described);
        },
        name: 'inspect_roots_by_asking',
        description: 'Reports the workspace roots by returning a roots ask.',
    )
    ->build()
    ->run(new StdioTransport());
