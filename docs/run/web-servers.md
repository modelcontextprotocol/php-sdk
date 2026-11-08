# Web servers

The examples run on PHP's built-in server, `php -S`. In production, PHP usually runs under
PHP-FPM behind Nginx or Apache, and every HTTP request is handled by one of several worker
processes. This page shows a configuration for both web servers and what changes on the way
from `php -S`.

## The entry point

One PHP file answers every request to the MCP endpoint. Keep it in a public directory, and
the rest of the project outside of it:

```
/var/www/mcp-server/
├── public/
│   └── index.php
├── src/
├── var/sessions/
└── vendor/
```

```php-file
<?php

use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;

require dirname(__DIR__).'/vendor/autoload.php';

$server = Server::builder()
    ->setServerInfo('My Server', '1.0.0')
    ->setDiscovery(dirname(__DIR__), ['src'])
    ->setSession(new FileSessionStore(dirname(__DIR__).'/var/sessions'))
    ->build();

$transport = new StreamableHttpTransport(
    (new Psr17Factory())->createServerRequestFromGlobals(),
    middleware: [
        new CorsMiddleware(),
        new DnsRebindingProtectionMiddleware(allowedHosts: ['mcp.example.com']),
    ],
);

(new SapiEmitter())->emit($server->run($transport));
```

Besides `mcp/sdk`, this needs a PSR-17 implementation, `laminas/laminas-httphandlerrunner` and
`symfony/finder`, see [Optional packages](../get-started/installation.md#optional-packages).

Two settings decide whether clients get through:

* **Sessions.** PHP keeps nothing in memory from one request to the next, so the default
  `InMemorySessionStore` loses a session when the request that created it ends.
  `FileSessionStore` keeps sessions on disk. Its directory must be writable by the user
  PHP-FPM runs as, `www-data` on Debian and Ubuntu. See
  [Sessions over HTTP](sessions.md#sessions-over-http) for stores shared across hosts and
  [Concurrent requests](sessions.md#concurrent-requests) for the session lock.
* **Allowed hosts.** `DnsRebindingProtectionMiddleware` accepts `localhost`, `127.0.0.1` and
  `[::1]` by default and answers any other `Host` with `403`. List the host names your clients
  connect to, see [DNS rebinding protection](http.md#dns-rebinding-protection).

## Nginx

```nginx
server {
    listen 80;
    server_name mcp.example.com;
    root /var/www/mcp-server/public;

    location = /mcp {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }

    location / {
        return 404;
    }
}
```

The first location passes every HTTP method to PHP. The transport handles `POST`, `DELETE`
and `OPTIONS` and answers the others itself. The second answers every other path, including
`/index.php`, which Nginx would otherwise serve as a file, source and all.

Streaming needs no extra configuration. A streamed response carries `X-Accel-Buffering: no`,
which turns off Nginx's FastCGI buffering for that response, so each event reaches the client
when the handler sends it.

## Apache

Apache passes requests to PHP-FPM through `mod_proxy_fcgi`. On Debian and Ubuntu, enable it
with `a2enmod proxy_fcgi`.

```apache
<VirtualHost *:80>
    ServerName mcp.example.com
    DocumentRoot /var/www/mcp-server/public

    <Directory /var/www/mcp-server/public>
        Require all granted
        CGIPassAuth On
    </Directory>

    Alias /mcp /var/www/mcp-server/public/index.php

    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/php-fpm.sock|fcgi://localhost"
    </FilesMatch>

    <Proxy "fcgi://localhost">
        ProxySet flushpackets=on
    </Proxy>
</VirtualHost>
```

Two lines are easy to miss:

* `flushpackets=on` makes `mod_proxy_fcgi` pass on each event as PHP flushes it. Without it,
  Apache holds back a streamed response until it ends. A tool that asks the client for input
  then waits for an answer to a request the client never received.
* `CGIPassAuth On` passes the `Authorization` header to PHP, which Apache leaves out by
  default. Without it, [token validation](authorization.md) never sees a token. The
  `php-fpm` configuration that Debian and Ubuntu ship does the same with `SetEnvIfNoCase`.

## Workers and long-lived requests

A PHP-FPM worker handles one request at a time, and some MCP requests stay open:

* A handler that asks a handshake-era client for input (elicitation, sampling) keeps its
  request open until the client answers. The answer arrives as a second request and needs a
  free worker of its own. When all workers hold such requests, the answers wait in line and
  the handlers wait until the client gives up. Size `pm.max_children` (5 in the pool that
  Debian and Ubuntu ship) for these requests on top of your regular traffic.
* `subscriptions/listen` holds a worker for as long as the stream is open, see
  [How long a stream lives](subscriptions.md#how-long-a-stream-lives).

`php -S` runs a single worker, so the first case hangs there as well. Start it with
`PHP_CLI_SERVER_WORKERS=4` to try such handlers locally.

An open stream writes a keep-alive comment every 250 ms, so the read timeouts of Nginx and
Apache don't end it. Neither does
[`max_execution_time`](https://www.php.net/manual/en/info.configuration.php#ini.max-execution-time)
in a non-thread-safe build on Linux, the one distributions ship as PHP-FPM: it counts CPU
time, not the time a stream spends waiting. A thread-safe build counts elapsed time since
PHP 8.3 and ends the stream with a fatal error. PHP-FPM's `request_terminate_timeout` counts
elapsed time in every build and kills the worker. In both cases the client receives no closing
message, so keep `setSubscriptionLifetime()` below these limits.

## The GET stream

The Streamable HTTP transport lets a handshake-era client open a `GET` request to receive
server messages outside of a request. This SDK doesn't offer that stream and answers `405`,
which the specification allows. Messages queued for a session go out with the response to its
next `POST` instead.
