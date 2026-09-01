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
use Mcp\Server\Server;
use Mcp\Server\Session\FileSessionLock;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;

require dirname(__DIR__).'/vendor/autoload.php';

$server = Server::builder()
    ->setServerInfo('My Server', '1.0.0')
    ->setDiscovery(dirname(__DIR__), ['src'])
    ->setSession(new FileSessionStore(dirname(__DIR__).'/var/sessions'))
    ->setSessionLock(new FileSessionLock(dirname(__DIR__).'/var/sessions'))
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

Three settings decide whether clients get through:

* **Sessions.** A server built without `setSession()` has
  [no sessions](sessions.md#serving-without-sessions). PHP keeps nothing in memory from one
  request to the next, so `InMemorySessionStore` loses a session when the request that created it ends.
  `FileSessionStore` keeps sessions on disk, and `FileSessionLock` on the same directory
  serializes the requests of one session: without it, two requests of one session that run on
  two workers at the same time overwrite each other's changes, and tool calls an agent sends
  in parallel lose their answers. The directory must be writable by the user PHP-FPM runs as,
  `www-data` on Debian and Ubuntu. See [Sessions over HTTP](sessions.md#sessions-over-http)
  for stores shared across hosts and [Concurrent requests](sessions.md#concurrent-requests)
  for the lock.
* **Allowed hosts.** `DnsRebindingProtectionMiddleware` accepts `localhost`, `127.0.0.1` and
  `[::1]` by default and answers any other `Host` with `403`. List the host names your clients
  connect to, see [DNS rebinding protection](http.md#dns-rebinding-protection).
* **Allowed origins.** A client that runs in a browser sends an `Origin` header, and a
  cross-origin request is answered `403` even with an allowed `Host`. List those origins in
  `DnsRebindingProtectionMiddleware` and `CorsMiddleware` both, see
  [CORS configuration](http.md#cors-configuration). Clients outside a browser send no `Origin`
  and need nothing here.

Both configurations below listen on plain HTTP, which is fine on a machine of your own. Over a
network, terminate TLS in the web server (`listen 443 ssl` in Nginx, `SSLEngine on` in Apache)
before clients send bearer tokens.

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
        client_max_body_size 4m;
    }

    location / {
        return 404;
    }
}
```

!!! note
    The systemd unit of the Debian and Ubuntu packages creates `/run/php/php-fpm.sock`, a link to the versioned
    socket (e.g. `/run/php/php8.4-fpm.sock`). Without systemd, for example in a container, use the path from `listen`
    in your pool configuration, here and in the Apache configuration below.

The first location passes every HTTP method to PHP. The transport handles `POST`, `DELETE`
and `OPTIONS` and answers the others itself. The second answers every other path, including
`/index.php`, which Nginx would otherwise serve as a file, source and all.

`client_max_body_size` lifts Nginx's limit on request bodies from 1 MB to the 4 MiB the
transport accepts by default, see [Request body size limit](http.md#request-body-size-limit).
With a lower limit, Nginx answers a larger `POST` with `413` before PHP sees it. Apache's
`LimitRequestBody` is far above that by default.

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

    AliasMatch "^/mcp$" /var/www/mcp-server/public/index.php

    <Location "/index.php">
        Require all denied
    </Location>

    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/php-fpm.sock|fcgi://localhost"
    </FilesMatch>

    <Proxy "fcgi://localhost">
        ProxySet flushpackets=on
    </Proxy>
</VirtualHost>
```

`AliasMatch` maps `/mcp` alone to the entry point, and the `Location` block keeps `/index.php`
from being requested directly, so Apache, like Nginx, hands PHP a single path. Two lines are
easy to miss:

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
  [How long a stream lives](subscriptions.md#how-long-a-stream-lives). The entry point above
  acknowledges such a stream with nothing and closes it at once: the server announces
  `listChanged` only once it has a notification bus, and under PHP-FPM that bus must be shared
  across workers, see [The notification bus](subscriptions.md#the-notification-bus).
* While a tool runs, the session lock makes the other requests of its session wait, and each
  of them holds a worker. After 30 seconds, they are answered with `503`. If your tools run
  longer, pass a higher timeout to `FileSessionLock`, see
  [Concurrent requests](sessions.md#concurrent-requests).

`php -S` runs a single worker, so the first case hangs there as well. Start it with
`PHP_CLI_SERVER_WORKERS=4` to try such handlers locally.

An open stream writes a keep-alive comment every 250 ms, so the read timeouts of the web
server don't end it. A tool that computes, or waits for anything but the client, sends nothing
while it runs, and the read timeout ends its request with a `504`: Nginx's
`fastcgi_read_timeout` after 60 seconds, Apache's `ProxyTimeout`, which falls back to `Timeout`
(60 seconds compiled in, 300 in the configuration Debian and Ubuntu ship).

When a tool legitimately runs longer, raise `fastcgi_read_timeout` in the Nginx location. In
Apache, add `timeout=` to the `ProxySet` line, for example `ProxySet flushpackets=on timeout=300`.
`ProxyTimeout` and `Timeout` also work in the `VirtualHost` block, but Apache refuses to start
with them in a `Location` or `Directory` block.

[`max_execution_time`](https://www.php.net/manual/en/info.configuration.php#ini.max-execution-time)
doesn't end a waiting stream in a non-thread-safe build on Linux, the one distributions ship
as PHP-FPM: it counts CPU time, not the time a stream spends waiting. A thread-safe build
counts elapsed time, by default since PHP 8.3, and ends the stream with a fatal error.
PHP-FPM's `request_terminate_timeout`, off by default, counts elapsed time in every build
and kills the worker. In both cases the client receives no closing message, so keep
`setSubscriptionLifetime()` below these limits.

## The GET stream

The Streamable HTTP transport lets a handshake-era client open a `GET` request to receive
server messages outside of a request. This SDK doesn't offer that stream and answers `405`,
which the specification allows. Messages queued for a session go out ahead of the response to
its next `POST` that carries a request. A `POST` with only a notification or a response is
answered `202` and leaves the queue as it is.
