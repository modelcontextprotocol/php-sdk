# Running your server

`Server::builder()` configures a server; `run()` puts it on a transport and starts
answering. Every transport implements `TransportInterface` and is used the same way:

```php
$server = Server::builder()
    ->setServerInfo('My Server', '1.0.0')
    ->setDiscovery(__DIR__, ['.'])
    ->build();

$transport = new SomeTransport();

$result = $server->run($transport); // Blocks for STDIO, returns a response for HTTP
```

## Choosing a transport

The choice depends on the client you want to integrate with:

* The client runs **locally** and launches your server as a subprocess (Claude Desktop,
  most editors) → **[STDIO](stdio.md)**.
* The client is **remote**, or your server lives in a web application →
  **[HTTP](http.md)**, the Streamable HTTP transport.

The rest of this section:

* **[Server builder](server-builder.md)** — every configuration knob: server info,
  discovery, dependency injection, logging, pagination.
* **[Framework integration](framework-integration.md)** — mounting the HTTP transport in
  a Symfony, Laravel, or Slim application, or running it standalone.
* **[Sessions](sessions.md)** — where per-client state lives, which matters as soon as
  you serve HTTP from more than one process.
* **[Authorization](authorization.md)** — validating OAuth 2 access tokens in front of
  the HTTP transport.

The same server also answers protocol revision `2026-07-28`, which has no handshake and no
sessions. Nothing above changes for it; what does is
**[The 2026-07-28 lifecycle](../lifecycle/index.md)**.
