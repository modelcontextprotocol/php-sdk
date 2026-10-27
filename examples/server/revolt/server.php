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

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Http\Discovery\Psr17Factory;
use Mcp\Example\Server\Revolt\RevoltFiberScheduler;
use Mcp\Example\Server\Revolt\SlowTools;
use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Revolt\EventLoop;

/*
 * An MCP server on the Revolt event loop, served by amphp/http-server.
 *
 * One long-running process serves all requests: while a tool awaits I/O, the
 * loop serves other requests. The fiber scheduler lets the loop drive the
 * tool's fiber until the tool returns.
 *
 *     php examples/server/revolt/server.php [127.0.0.1:8000]
 *
 * Responses are buffered, so a tool that waits on the client (elicitation,
 * sampling) is not supported here.
 */

$address = $argv[1] ?? '127.0.0.1:8000';

$server = Server::builder()
    ->setServerInfo('Revolt Server', '1.0.0')
    ->setLogger(logger())
    ->setContainer(container())
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

    return new Response($response->getStatusCode(), $response->getHeaders(), (string) $response->getBody());
}), new DefaultErrorHandler());

logger()->info(sprintf('Revolt MCP server listening on http://%s', $address));

EventLoop::run();
