<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// Server for HttpRedirectTest under `php -S`: redirects `tools/call` as its query string says and logs what reaches /moved.

$message = json_decode((string) file_get_contents('php://input'), true);
$method = $message['method'] ?? null;

$reply = static function (array $result) use ($message): void {
    header('Content-Type: application/json');
    echo json_encode(['jsonrpc' => '2.0', 'id' => $message['id'] ?? null, 'result' => $result]);
};

$answer = ['content' => [['type' => 'text', 'text' => 'answered after the redirect']], 'isError' => false, 'resultType' => 'complete'];

if ('/moved' === parse_url($_SERVER['REQUEST_URI'], \PHP_URL_PATH)) {
    file_put_contents((string) getenv('MCP_REDIRECT_LOG'), $_SERVER['REQUEST_METHOD'].\PHP_EOL, \FILE_APPEND);
    $reply($answer);

    return;
}

if ('server/discover' === $method) {
    $reply([
        'supportedVersions' => ['2026-07-28'],
        'capabilities' => ['tools' => new stdClass()],
        'serverInfo' => ['name' => 'redirecting-server', 'version' => '1.0.0'],
        'resultType' => 'complete',
        'ttlMs' => 0,
        'cacheScope' => 'public',
    ]);

    return;
}

if ('tools/call' === $method) {
    http_response_code((int) $_GET['status']);
    header('Location: '.$_GET['location']);

    return;
}

http_response_code(202);
