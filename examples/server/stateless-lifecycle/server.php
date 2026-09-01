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

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Enum\CacheScope;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server\RequestContext;
use Mcp\Server\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Subscription\InMemoryNotificationBus;
use Mcp\Server\Wire\CachePolicy;

/*
 * A server showing off the modern (2026-07-28) lifecycle.
 *
 * What is different about that lifecycle: there is no `initialize` handshake
 * and no session. Each request carries its own protocol version and client
 * capabilities in `_meta`, so it can be scaled to as many processes as you like
 * without any of them sharing state.
 *
 * What is *not* different: the endpoint. Like every other example here, this one
 * serves both eras — a request is classified before anything else looks at it
 * and routed to the lifecycle it belongs to. The settings below are the ones the
 * modern era makes use of; a handshake-era client reaches the same tools and
 * simply never sees them.
 *
 *     php -S 127.0.0.1:8000 examples/server/stateless-lifecycle/server.php
 *
 * Call it the modern way — every request carries the headers *and* the `_meta`:
 *
 *     curl -sS http://127.0.0.1:8000/ \
 *       -H 'Content-Type: application/json' \
 *       -H 'Accept: application/json, text/event-stream' \
 *       -H 'MCP-Protocol-Version: 2026-07-28' \
 *       -H 'Mcp-Method: server/discover' \
 *       -d '{"jsonrpc":"2.0","id":1,"method":"server/discover","params":{"_meta":{
 *             "io.modelcontextprotocol/protocolVersion":"2026-07-28",
 *             "io.modelcontextprotocol/clientCapabilities":{}}}}'
 *
 * Or the handshake way, against the same URL:
 *
 *     curl -sS http://127.0.0.1:8000/ \
 *       -H 'Content-Type: application/json' \
 *       -H 'Accept: application/json, text/event-stream' \
 *       -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{
 *             "protocolVersion":"2025-11-25","capabilities":{},
 *             "clientInfo":{"name":"curl","version":"1.0.0"}}}'
 */

$bus = new InMemoryNotificationBus();

$server = Server::builder()
    ->setServerInfo('Stateless Lifecycle Demo', '1.0.0', title: 'Stateless Lifecycle Demo')
    ->setLogger(logger())
    ->setCapabilities(new ServerCapabilities(tools: true, toolsListChanged: true, resources: true, logging: true))

    // Only the handshake leg has anything to keep here: its clients open a
    // session and come back to it. The modern leg never mints one, so under
    // `php -S` — where nothing survives between requests — this is what lets
    // both eras reach the same tools.
    ->setSession(new FileSessionStore(__DIR__.'/sessions'))

    // How long an answer stays fresh. Lists are the same for every caller here,
    // so they are public and long-lived; anything user-shaped stays private.
    ->setCachePolicy(
        CachePolicy::default(30_000)
            ->withMethod('tools/list', 3_600_000, CacheScope::Public)
            ->withMethod('server/discover', 3_600_000, CacheScope::Public),
    )

    // Signs the `requestState` a multi round-trip answer carries. The same key
    // must reach every process that might serve the retry — a per-process
    // random value only works for a single-process deployment.
    ->setRequestState(getenv('MCP_REQUEST_STATE_KEY') ?: str_repeat('example-development-key-', 2))

    // Carries change notifications to open `subscriptions/listen` streams.
    // In-memory suits `php -S` and worker runtimes; under PHP-FPM use
    // Psr16NotificationBus, since the publisher and the stream are different
    // processes there.
    ->setNotificationBus($bus)
    ->setSubscriptionLifetime(20.0)

    // An ordinary tool: nothing about it is lifecycle-specific.
    ->addTool(
        static fn (string $city = 'Berlin'): string => sprintf('It is 17°C and cloudy in %s.', $city),
        name: 'get_weather',
        description: 'Reports the weather for a city',
    )

    // Progress and logging both travel on this request's own response stream.
    // The client opts into each: progress by sending `_meta.progressToken`,
    // logging by sending `_meta["io.modelcontextprotocol/logLevel"]`. Without
    // those the server must stay silent, and does.
    ->addTool(
        static function (RequestContext $context, int $steps = 3): string {
            $client = $context->getClientGateway();

            for ($step = 1; $step <= $steps; ++$step) {
                $client->log(LoggingLevel::Info, sprintf('Reindexing shard %d of %d', $step, $steps));
                $client->progress($step, $steps, sprintf('Shard %d of %d', $step, $steps));
            }

            return sprintf('Reindexed %d shards.', $steps);
        },
        name: 'reindex',
        description: 'Reindexes shards, reporting progress as it goes',
    )

    // A tool that needs something from the user. There are no server-initiated
    // requests in this revision: instead of asking the client and waiting, the
    // server *returns* the ask and the client retries the whole call with the
    // answer. Nothing is kept between the two rounds — what the server needs to
    // remember it seals into `requestState`, which comes back verified.
    //
    // No fork for the handshake era, and none needed: there the SDK fulfils the
    // same ask over that connection's own channel and re-enters this closure
    // with the answer. Which is why it re-derives where it is from what came
    // back rather than keeping anything of its own.
    ->addTool(
        static function (RequestContext $context): CallToolResult|InputRequiredResult {
            $answer = $context->getInputContext()?->elicitResult('who');

            if (null === $answer) {
                return new InputRequiredResult(
                    ['who' => new ElicitRequest(
                        'What name should the greeting use?',
                        new ElicitationSchema(['name' => new StringSchemaDefinition('Name')], ['name']),
                    )],
                    requestState: $context->mintRequestState(['asked' => 'who']),
                );
            }

            return new CallToolResult([new TextContent(sprintf('Hello, %s!', $answer->content['name'] ?? 'friend'))]);
        },
        name: 'greet',
        description: 'Greets you by name, asking for it first if it has to',
    )
    ->build();

shutdown($server->run(transport()));
