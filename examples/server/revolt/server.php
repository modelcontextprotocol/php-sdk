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

use Amp\ByteStream\ReadableIterableStream;
use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Http\Discovery\Psr17Factory;
use Mcp\Example\Server\Revolt\RevoltFiberScheduler;
use Mcp\Example\Server\Revolt\SlowTools;
use Mcp\Server;
use Mcp\Server\Subscription\InMemoryNotificationBus;
use Mcp\Server\Transport\CallbackStream;
use Mcp\Server\Transport\StreamableHttpTransport;
use Revolt\EventLoop;

use function Amp\delay;

/*
 * An MCP server on the Revolt event loop, served by amphp/http-server.
 *
 * One long-running process serves all requests: while a tool awaits I/O, the
 * loop serves other requests. The fiber scheduler lets the loop drive the
 * tool's fiber until the tool returns.
 *
 *     php examples/server/revolt/server.php [127.0.0.1:8000]
 *
 * The SSE text of a 2026-07-28 streamed response comes from
 * CallbackStream::chunks(), which this server writes itself. A handshake-era
 * response that needs streaming, when a tool sends a notification (e.g.
 * progress) or waits on the client on a session, is answered with 501.
 */

$address = $argv[1] ?? '127.0.0.1:8000';

$server = Server::builder()
    ->setServerInfo('Revolt Server', '1.0.0')
    ->setLogger(logger())
    ->setContainer(container())
    ->setNotificationBus(new InMemoryNotificationBus())
    ->setFiberScheduler(new RevoltFiberScheduler())
    ->addTool([SlowTools::class, 'wait'], 'wait')
    ->addTool([SlowTools::class, 'checkServices'], 'check_services')
    ->build();

$factory = new Psr17Factory();

$http = SocketHttpServer::createForDirectAccess(logger());
$http->expose($address);
$http->start(new ClosureRequestHandler(static function (Request $request) use ($server, $factory): Response {
    $psrRequest = $factory->createServerRequest($request->getMethod(), (string) $request->getUri())
        ->withBody($factory->createStream($request->getBody()->buffer()));
    foreach ($request->getHeaders() as $name => $values) {
        $psrRequest = $psrRequest->withHeader($name, $values);
    }

    $response = $server->run(new StreamableHttpTransport($psrRequest, $factory, $factory, logger()));

    $body = $response->getBody();
    $chunks = $body instanceof CallbackStream ? $body->chunks() : null;

    if (null !== $chunks) {
        return new Response($response->getStatusCode(), $response->getHeaders(), new ReadableIterableStream(paced($chunks)));
    }

    if ($body instanceof CallbackStream) {
        return new Response(HttpStatus::NOT_IMPLEMENTED, ['Content-Type' => 'text/plain'], 'This server does not support streamed responses.');
    }

    return new Response($response->getStatusCode(), $response->getHeaders(), (string) $body);
}), new DefaultErrorHandler());

/**
 * On an idle turn of the stream, a keep-alive comment and a wait on the loop.
 *
 * @param Generator<int, string> $chunks
 *
 * @return Generator<int, string>
 */
function paced(Generator $chunks): Generator
{
    foreach ($chunks as $chunk) {
        if ('' === $chunk) {
            yield ": keep-alive\n\n";
            delay(0.25);

            continue;
        }

        yield $chunk;
    }
}

logger()->info(sprintf('Revolt MCP server listening on http://%s', $address));

EventLoop::run();
