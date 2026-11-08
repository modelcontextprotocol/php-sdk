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
use Mcp\Server;
use Mcp\Server\Transport\CallbackStream;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;

use function React\Async\async;

/*
 * An MCP server on the ReactPHP event loop, served by react/http.
 *
 * One long-running process serves all requests: while a tool awaits I/O, the
 * loop serves other requests. The fiber scheduler lets the loop drive the
 * tool's fiber until the tool returns.
 *
 *     php examples/server/reactphp/server.php [127.0.0.1:8000]
 *
 * The transport writes streamed responses to the PHP output, which this server
 * doesn't send to the client. So it answers with 501 when a tool sends a
 * notification (e.g. progress) or waits on the client (elicitation, sampling)
 * on a session, and for subscriptions/listen.
 */

$address = $argv[1] ?? '127.0.0.1:8000';

$server = Server::builder()
    ->setServerInfo('ReactPHP Server', '1.0.0')
    ->setLogger(logger())
    ->setContainer(container())
    ->setFiberScheduler(new ReactFiberScheduler())
    ->addTool([SlowTools::class, 'wait'], 'wait')
    ->addTool([SlowTools::class, 'checkServices'], 'check_services')
    ->build();

$factory = new Psr17Factory();

$http = new HttpServer(async(static function (ServerRequestInterface $request) use ($server, $factory): Response {
    $response = $server->run(new StreamableHttpTransport($request, $factory, $factory, logger()));
    $body = $response->getBody();

    if ($body instanceof CallbackStream) {
        return new Response(Response::STATUS_NOT_IMPLEMENTED, ['Content-Type' => 'text/plain'], 'This server does not support streamed responses.');
    }

    return new Response($response->getStatusCode(), $response->getHeaders(), (string) $body);
}));
$http->listen(new SocketServer($address));

logger()->info(sprintf('ReactPHP MCP server listening on http://%s', $address));
