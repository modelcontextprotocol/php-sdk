# Authorization

A remote MCP server that answers `401 Unauthorized` is not broken — it is telling you
where to get a token. Pass an authenticator to the HTTP transport and the client takes
that hint and follows it:

```php
use Mcp\Client;
use Mcp\Client\Auth\OAuth;
use Mcp\Client\Transport\HttpTransport;

$client = Client::builder()->setClientInfo('My App', '1.0.0')->build();

$client->connect(new HttpTransport(
    'https://mcp.example.com/mcp',
    auth: OAuth::forApplication('My App')->build(),
));
```

That is the whole setup. On the first challenge the client reads the resource's metadata,
finds its authorization server, registers itself, opens the user's browser, catches the
redirect on a loopback port, exchanges the code, and retries the request with the token.
Every request after that carries the token; when the server later demands a scope the
token does not have, the same thing happens again for just that scope.

Nothing happens until a server actually asks. A client configured with `auth:` talks to
an unprotected server exactly as one without it does.

## What the client does on a challenge

| Step | Specification |
| --- | --- |
| Read `WWW-Authenticate` for the metadata URL and the required scopes | [RFC 9728](https://datatracker.ietf.org/doc/html/rfc9728) |
| Fetch the protected resource metadata, and check it describes *this* server | [RFC 9728](https://datatracker.ietf.org/doc/html/rfc9728) |
| Fetch the authorization server's metadata, and check it is the server that was asked for | [RFC 8414](https://datatracker.ietf.org/doc/html/rfc8414) |
| Register the client, unless one was configured or already stored | [RFC 7591](https://datatracker.ietf.org/doc/html/rfc7591) |
| Run the authorization code flow with PKCE | [RFC 6749](https://datatracker.ietf.org/doc/html/rfc6749), [RFC 7636](https://datatracker.ietf.org/doc/html/rfc7636) |
| Verify `state` and `iss` on the way back | [RFC 9207](https://datatracker.ietf.org/doc/html/rfc9207) |
| Name the resource the token is for, in both requests | [RFC 8707](https://datatracker.ietf.org/doc/html/rfc8707) |
| Present the token, and refresh or re-authorize when it stops working | [RFC 6750](https://datatracker.ietf.org/doc/html/rfc6750) |

Discovery, PKCE and the `resource` parameter are not optional extras — a client that
skips them either fails against conformant servers or hands its tokens to whoever asks.
None of them are switches you have to find.

## Getting the user to the browser and back

The one step the SDK cannot do alone is putting the authorization URL in front of a
person. Which handler fits depends on what your application is:

```php
use Mcp\Client\Auth\LoopbackAuthorizationHandler;
use Mcp\Client\Auth\OAuth;

$auth = OAuth::forApplication('My App')
    ->setAuthorizationHandler(new LoopbackAuthorizationHandler())
    ->build();
```

- **`LoopbackAuthorizationHandler`** (the default) opens the system browser and listens
  on the redirect URI's port until the redirect lands. Right for desktop and command
  line applications; the authorization code never touches the clipboard.
- **`ConsoleAuthorizationHandler`** prints the URL and reads the redirected URL back from
  standard input. For a remote shell, a container without a browser, or a server that
  will not accept a loopback redirect URI. It wants the whole URL, not just the code —
  the rest of it is what the response is checked against.
- **`HeadlessAuthorizationHandler`** requests the authorization endpoint itself and reads
  the code out of the redirect, with no user at all. Only works where the authorization
  server grants without prompting — a test harness, or an enterprise identity provider
  that has already consented.

Anything else is an interface with one method: give it the URL, hand back the query
parameters the authorization server redirected with.

```php
use Mcp\Client\Auth\AuthorizationHandlerInterface;

final class QueueTheUserForApproval implements AuthorizationHandlerInterface
{
    public function authorize(string $authorizationUrl, string $redirectUri): array
    {
        // Show $authorizationUrl in your UI, wait for your callback route to be hit,
        // and return the query parameters it received.
        return ['code' => '…', 'state' => '…', 'iss' => '…'];
    }
}
```

`state` and `iss` are verified by the SDK, so return them if you have them.

### The redirect URI

The default is `http://127.0.0.1:8765/callback`. Change it with `setRedirectUri()` when
the port is taken, or when the authorization server has a different one on file:

```php
OAuth::forApplication('My App')->setRedirectUri('http://127.0.0.1:9876/callback');
```

The loopback handler listens on whatever host and port that URI names, so the two stay
in step by construction.

## Remembering credentials between runs

By default the tokens live for the lifetime of the process, which means a command line
tool sends its user to the browser on every invocation. Point it at a file instead:

```php
use Mcp\Client\Auth\FileCredentialStorage;
use Mcp\Client\Auth\OAuth;

$auth = OAuth::forApplication('My App')
    ->setCredentialStorage(new FileCredentialStorage($_SERVER['HOME'].'/.config/my-app/credentials.json'))
    ->build();
```

The file holds bearer tokens and, where the authorization server issues them, client
secrets, so it is written `0600` in a `0700` directory. It is not encrypted: an
application with a keychain or a secrets manager should implement
`CredentialStorageInterface` against that instead — five methods, keyed by the
authorization server's issuer identifier.

Keying by issuer is not incidental. Credentials mean nothing at a server that did not
issue them, so when a resource starts pointing at a different authorization server, the
client finds an empty store and registers afresh rather than presenting one server's
client id to another.

## Clients that are not a user

### A pre-registered client

Where an administrator has provisioned a client id — or the authorization server does
not offer dynamic registration at all:

```php
OAuth::forApplication('My App')->setClientCredentials('my-client-id', 'my-client-secret');
```

The secret is optional: a public client with PKCE is the norm for anything running on
the user's machine. Whether the secret travels in a Basic header, in the request body,
or not at all is decided from what the authorization server advertises.

### A URL as the client id

Servers that support client id metadata documents will read the client's metadata from a
URL, so one published document works everywhere instead of one registration per server:

```php
OAuth::forApplication('My App')->setClientMetadataUrl('https://my-app.example.com/client-metadata.json');
```

Used only where the server advertises support for it; otherwise the client registers
normally.

### A daemon with no user at all

```php
use Mcp\Client\Auth\OAuth;

$auth = OAuth::forServiceAccount('My Job', 'my-client-id', 'my-client-secret')->build();
```

No browser, no redirect, no authorization handler: the client authenticates as itself and
gets a token. Where the authorization server prefers a signed assertion to a shared
secret:

```php
OAuth::forServiceAccount('My Job', 'my-client-id')
    ->setPrivateKeyJwt(file_get_contents('/etc/my-job/private-key.pem'), 'ES256');
```

### A user who already signed in at work

Cross-app access turns an enterprise identity into a token for one MCP server, without a
second consent screen: the client trades the identity token it already holds for an
authorization grant scoped to that server, then presents the grant to the server's
authorization server.

```php
use Mcp\Client\Auth\CrossAppAccess;
use Mcp\Client\Auth\OAuth;

$auth = OAuth::forApplication('My App')
    ->setClientCredentials('my-client-id', 'my-client-secret')
    ->setCrossAppAccess(new CrossAppAccess(
        tokenEndpoint: 'https://idp.example.com/token',
        identityToken: $idTokenFromYourSignIn,
    ))
    ->build();
```

The identity token comes from wherever your application signs its users in; the SDK never
obtains one.

## A token you already have

Sometimes there is nothing to negotiate — the token came from a secrets manager, or a
user pasted a personal access token into a config file:

```php
use Mcp\Client\Auth\BearerToken;
use Mcp\Client\Transport\HttpTransport;

$transport = new HttpTransport('https://mcp.example.com/mcp', auth: new BearerToken($token));
```

## Scopes

Left alone, the client asks for what it is told to ask for: the scopes named in the
challenge if there are any, otherwise every scope the resource advertises, otherwise
nothing at all. Inventing scopes only earns a rejection, so it does not.

By default the client will not authorize against a server that publishes no protected
resource metadata: every revision since 2025-06-18 requires it, and without it there is
nothing to check the authorization server against. `setLegacyDiscovery(true)` opts back
in to the 2025-03-26 behaviour of treating the MCP server as its own authorization
server.

Where the authorization server offers `offline_access`, the client asks for it too, so an
expired token can be refreshed instead of sending the user back to the browser. A server
that advertises the scope but will not grant it to this client is not a failure — the
client notices and retries without it. Turn it off with `setOfflineAccess(false)` if you
would rather not hold a refresh token.

To pin the request to an exact set:

```php
OAuth::forApplication('My App')->setScopes('mcp:read', 'mcp:write');
```

## Authenticating something other than MCP

The authenticator is a PSR-18 decorator underneath, so the same credentials can be used
for anything else you send to that server:

```php
use Mcp\Client\Auth\AuthenticatingHttpClient;

$http = new AuthenticatingHttpClient($yourPsr18Client, $auth);
```

It authenticates every request and retries a challenged one, capped at three
authorization attempts so a server stuck on "insufficient scope" cannot spin the client
in a loop.

## What the client refuses to do

A remote MCP server is not a trusted party. It chooses its own `WWW-Authenticate`
challenge, its own metadata document, and therefore which authorization server the
client is about to talk to. The client treats all of that as input, and there are
several answers it will not accept:

| The server says | The client does |
| --- | --- |
| metadata naming a `resource` that is not the endpoint being called | refuses; a token for somewhere else is how a token ends up at the wrong party |
| authorization server metadata whose own `issuer` is not the issuer it was fetched for | refuses, and does not use any endpoint from that document |
| an `authorization_endpoint` or `token_endpoint` that is not `https` | refuses, unless the host is loopback |
| an authorization response without the `state` this client sent | refuses |
| an `iss` that is not the issuer the flow started with, compared byte for byte | refuses |
| an `iss` missing when the server said it would send one | refuses |
| no protected resource metadata at all | refuses, unless `setLegacyDiscovery(true)` |
| `401` again, forever | gives up after three authorization attempts |

Two further properties are worth knowing because they are what keeps a mistake elsewhere
from becoming a leak:

- **A token is only ever sent to the resource it was minted for.** Not to the host, to
  the resource: `https://example.com/mcp` does not authorize a request to
  `https://example.com/other`. Sharing one authenticator across two servers by accident
  cannot leak the first server's token to the second.
- **Credentials are stored per authorization server issuer.** A resource that starts
  pointing somewhere new gets a fresh registration; the previous server's client id is
  never presented to the new one.

What the client cannot defend against is an authorization server the user genuinely
approves. If a hostile MCP server points at an authorization server, and the user signs
in there and consents, the resulting token is exactly what they agreed to. The
authorization URL is shown to the user for that reason — it is the one point in the flow
where a human decides.

## When it does not work

Everything the flow can refuse to do arrives as `Mcp\Exception\AuthorizationException`,
including a server that is still answering `401` after the client has done everything it
can:

```php
use Mcp\Exception\AuthorizationException;

try {
    $client->connect($transport);
} catch (AuthorizationException $e) {
    echo "Could not authorize: {$e->getMessage()}\n";
}
```

A few of these are refusals on purpose, not bugs to work around: metadata that claims a
resource the server is not, an authorization server whose metadata describes somebody
else, a redirect naming an issuer the flow did not start with. Each one is what a token
being routed to the wrong place looks like from the client's side.

Pass a PSR-3 logger to see each step:

```php
OAuth::forApplication('My App')->setLogger($logger);
```

## See also

- [Transports](transports.md) — the HTTP transport's other options.
- [Authorization](../run/authorization.md) — the server side of the same conversation.
- [Error handling](errors.md) — the rest of the client's exceptions.
