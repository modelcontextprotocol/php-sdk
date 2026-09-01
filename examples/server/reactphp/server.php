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

use Http\Discovery\Psr17Factory;
use Mcp\Example\Server\ReactPhp\ReactFiberScheduler;
use Mcp\Example\Server\ReactPhp\SlowTools;
use Mcp\Server\Server;
use Mcp\Server\Subscription\InMemoryNotificationBus;
use Mcp\Server\Transport\CallbackStream;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\Deferred;
use React\Socket\SocketServer;
use React\Stream\ThroughStream;

use function React\Async\async;
use function React\Async\await;
use function React\Async\delay;

/*
 * An MCP server on the ReactPHP event loop, served by react/http.
 *
 * One long-running process serves all requests: while a tool awaits I/O, the
 * loop serves other requests. The fiber scheduler lets the loop drive the
 * tool's fiber until the tool returns.
 *
 *     php examples/server/reactphp/server.php [127.0.0.1:8000]
 *
 * The SSE text of a streamed response comes from CallbackStream::chunks(),
 * which this server writes itself.
 */

$address = $argv[1] ?? '127.0.0.1:8000';

$server = Server::builder()
    ->setServerInfo('ReactPHP Server', '1.0.0')
    ->setLogger(logger())
    ->setContainer(container())
    ->setNotificationBus(new InMemoryNotificationBus())
    ->setFiberScheduler(new ReactFiberScheduler())
    ->addTool([SlowTools::class, 'wait'], 'wait')
    ->addTool([SlowTools::class, 'checkServices'], 'check_services')
    ->build();

$factory = new Psr17Factory();

$http = new HttpServer(async(static function (ServerRequestInterface $request) use ($server, $factory): Response {
    $response = $server->run(new StreamableHttpTransport($request, $factory, $factory, logger()));
    $body = $response->getBody();
    $chunks = $body instanceof CallbackStream ? $body->chunks() : null;

    if (null !== $chunks) {
        $stream = new ThroughStream();
        // On the next tick, once the server reads the stream: data written before is lost.
        Loop::futureTick(static fn () => async(static fn () => pump($chunks, $stream))()->then(null, static function (Throwable $e): void {
            logger()->error('Streaming the response failed.', ['exception' => $e]);
        }));

        return new Response($response->getStatusCode(), $response->getHeaders(), $stream);
    }

    return new Response($response->getStatusCode(), $response->getHeaders(), (string) $body);
}));
$http->listen(new SocketServer($address));

/**
 * Writes the chunks to the stream, waiting on the loop on an idle turn and while the client reads slower.
 *
 * @param Generator<int, string> $chunks
 */
function pump(Generator $chunks, ThroughStream $stream): void
{
    while ($stream->isWritable() && $chunks->valid()) {
        $chunk = $chunks->current();

        if (!$stream->write('' === $chunk ? ": keep-alive\n\n" : $chunk)) {
            drained($stream);
        }

        if ('' === $chunk) {
            delay(0.25);
        }

        // Advancing runs the handler on, so only while the client is still there.
        if ($stream->isWritable()) {
            $chunks->next();
        }
    }

    $stream->end();
}

/**
 * Waits until the client read what the stream holds, or went away.
 */
function drained(ThroughStream $stream): void
{
    if (!$stream->isWritable()) {
        return;
    }

    $deferred = new Deferred();
    $done = static function () use ($deferred): void {
        $deferred->resolve(null);
    };
    $stream->on('drain', $done);
    $stream->on('close', $done);
    await($deferred->promise());
    $stream->removeListener('drain', $done);
    $stream->removeListener('close', $done);
}

logger()->info(sprintf('ReactPHP MCP server listening on http://%s', $address));
