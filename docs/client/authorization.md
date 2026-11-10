# Authorization

A remote MCP server can require an OAuth access token. It answers a request without a token with `401 Unauthorized`. Pass an authenticator to the HTTP transport and the client gets a token and sends the request again:

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

The client does nothing until a server answers with `401` or `403`. It talks to a server that doesn't require a token in the same way as a client without `auth`.

If you already have a token, see [Sending an access token](transports.md#sending-an-access-token).

## What the client does

When the server refuses a request, the client takes these steps:

| Step | Specification |
| --- | --- |
| Read the metadata URL and the required scopes from `WWW-Authenticate` | [RFC 9728](https://datatracker.ietf.org/doc/html/rfc9728) |
| Fetch the protected resource metadata and check that it describes this server | [RFC 9728](https://datatracker.ietf.org/doc/html/rfc9728) |
| Fetch the metadata of the authorization server and check its issuer | [RFC 8414](https://datatracker.ietf.org/doc/html/rfc8414) |
| Register the client, unless you configured a client or the storage has one | [RFC 7591](https://datatracker.ietf.org/doc/html/rfc7591) |
| Run the authorization code flow with PKCE | [RFC 6749](https://datatracker.ietf.org/doc/html/rfc6749), [RFC 7636](https://datatracker.ietf.org/doc/html/rfc7636) |
| Check `state` and `iss` in the authorization response | [RFC 9207](https://datatracker.ietf.org/doc/html/rfc9207) |
| Send the `resource` parameter in the authorization and token requests | [RFC 8707](https://datatracker.ietf.org/doc/html/rfc8707) |
| Send the token, and refresh it or authorize again when it stops working | [RFC 6750](https://datatracker.ietf.org/doc/html/rfc6750) |

## Sending the User to the Browser

An authorization handler sends the user to the authorization server and returns the answer. Pass one with `setAuthorizationHandler()`:

```php
use Mcp\Client\Auth\HeadlessAuthorizationHandler;
use Mcp\Client\Auth\OAuth;

$auth = OAuth::forApplication('My App')
    ->setAuthorizationHandler(new HeadlessAuthorizationHandler())
    ->build();
```

The SDK contains three handlers:

- `LoopbackAuthorizationHandler` is the default. It opens the system browser and listens on the port of the redirect URI until the browser returns. It also writes the URL to `STDERR`, so you can open it yourself if no browser starts. Use it for desktop and command line applications.
- `ConsoleAuthorizationHandler` prints the authorization URL and reads the URL of the redirect from the standard input. Use it in a remote shell or a container without a browser. The user must paste the whole URL, because the SDK checks its `state` and `iss`.
- `HeadlessAuthorizationHandler` requests the authorization URL with an HTTP client and reads the code from the redirect. It only works if the authorization server grants access without asking the user (e.g. a test server).

For anything else, implement `AuthorizationHandlerInterface`. It has one method:

```php
use Mcp\Client\Auth\AuthorizationHandlerInterface;

final class AcmeAuthorizationHandler implements AuthorizationHandlerInterface
{
    public function authorize(string $authorizationUrl, string $redirectUri): array
    {
        // send the user to $authorizationUrl and wait for the redirect to $redirectUri

        // return its query parameters, the SDK checks them
        return ['code' => '...', 'state' => '...', 'iss' => '...'];
    }
}
```

The default redirect URI is `http://127.0.0.1:8765/callback`. Change it with `setRedirectUri()` if the port is in use or if the authorization server expects another one. The URI must use `https` or a loopback host (e.g. `127.0.0.1`), and must not have a fragment or userinfo. Otherwise, `build()` throws an `InvalidArgumentException`. The loopback handler listens on the host and port of this URI, so it only works with `http` on a loopback host. For an `https` URI, implement your own handler.

## Storing Credentials

By default, the client keeps its tokens in memory. A command line application then sends the user to the browser on every run. Use `FileCredentialStorage` to keep them between runs:

```php
use Mcp\Client\Auth\FileCredentialStorage;
use Mcp\Client\Auth\OAuth;

$auth = OAuth::forApplication('My App')
    ->setCredentialStorage(new FileCredentialStorage($_SERVER['HOME'].'/.config/my-app/credentials.json'))
    ->build();
```

The file contains tokens and client secrets. The SDK creates it with the mode `0600` in a directory with the mode `0700`, and doesn't encrypt it. Windows ignores these modes, so store the file in a directory that only you can read (e.g. your user profile). The SDK also creates two lock files next to it, so processes can share the file and only one of them refreshes a token at a time. To use a keychain or a secrets manager, implement `CredentialStorageInterface`. Its `synchronize()` method must run one operation at a time across all processes that share the storage.

The storage keys everything by the issuer of the authorization server. If a server moves to another authorization server, the client registers again and doesn't send its old client ID to the new one. It also registers again if you change the redirect URI, if it needs scopes that its registration doesn't include, or if the token endpoint answers `invalid_client` for a client that it registered.

## Finishing the Authorization in a Later Request

A web application can't wait for the browser within one request. It redirects the user to the authorization server, which sends the user back to a callback route of the application. Use `RedirectAuthorizationHandler` for this, with one storage per user session:

```php
use Mcp\Client\Auth\FileCredentialStorage;
use Mcp\Client\Auth\OAuth;
use Mcp\Client\Auth\OAuthAuthenticator;
use Mcp\Client\Auth\RedirectAuthorizationHandler;

function createAuthenticator(): OAuthAuthenticator
{
    return OAuth::forApplication('Acme Shop')
        ->setRedirectUri('https://shop.example.com/oauth/callback')
        ->setAuthorizationHandler(new RedirectAuthorizationHandler())
        ->setCredentialStorage(new FileCredentialStorage('/var/lib/acme/mcp/'.hash('sha256', session_id()).'.json'))
        ->build();
}
```

Start the PHP session in both routes before you call this function. Without a session, `session_id()` returns an empty string, and all users share one file.

When the client needs a token, the handler throws an `AuthorizationPendingException`. Before that, the SDK stores the `state`, the PKCE verifier and the metadata of the authorization server. Redirect the browser to the URL of the exception:

```php
use Mcp\Client\Transport\HttpTransport;
use Mcp\Exception\AuthorizationPendingException;

try {
    $client->connect(new HttpTransport('https://mcp.example.com/mcp', auth: createAuthenticator()));
} catch (AuthorizationPendingException $e) {
    header('Location: '.$e->authorizationUrl);
    exit;
}
```

`connect()` and the requests after it throw this exception directly. In the callback route, pass the query parameters to `finishAuthorization()` of an authenticator with the same configuration and storage:

```php
createAuthenticator()->finishAuthorization($_GET);

// back to the page that needs the MCP server
header('Location: /orders');
```

The SDK checks the callback like any other, exchanges the code and stores the token. The next `connect()` with this storage uses it. If the authorization server refuses `offline_access`, `finishAuthorization()` throws an `AuthorizationPendingException` again, to authorize without that scope.

A pending authorization expires after 10 minutes. The SDK removes it before it checks the callback, so each callback works once. If `finishAuthorization()` throws an `AuthorizationException`, send the user through the authorization again.

!!! warning
    The SDK finds the pending authorization by the `state` parameter of the callback. Select the storage from the user session, never from the callback. With a storage that all users share, one user can finish an authorization that another user started.

`build()` throws an `InvalidArgumentException` if you use `RedirectAuthorizationHandler` without `setCredentialStorage()`. For your own storage, implement `PendingAuthorizationStorageInterface`. Its `consumePendingAuthorization()` method must read and remove the entry in one step, so two requests can't use the same callback.

## Using a Pre-registered Client

If an administrator created a client for you, or if the authorization server doesn't support dynamic registration, pass the client ID:

```php
OAuth::forApplication('My App')
    // the secret is optional
    ->setClientCredentials('my-client-id', 'my-client-secret')
    // required
    ->setExpectedIssuer('https://auth.example.com');
```

The MCP server names its authorization server. Without an expected issuer, a hostile MCP server could name its own and receive your secret. It could also send the user to your authorization server with your client ID and receive the authorization code. Pass the `issuer` value from the metadata of your authorization server. If you set a client ID without an expected issuer, `build()` throws an `InvalidArgumentException`.

The client reads from the metadata of the authorization server how to send the secret.

## Using a URL as the Client ID

If you publish a client ID metadata document, the authorization server can read your client's metadata from its URL. You then don't need a registration per server. The URL must use `https` and have a path. It can't contain a fragment, credentials or dot segments:

```php
OAuth::forApplication('My App')->setClientMetadataUrl('https://app.example.com/client-metadata.json');
```

The document must be a JSON object whose `client_id` is exactly this URL. It must also contain `client_name` and `redirect_uris`, and `redirect_uris` must list the redirect URI of the client (by default `http://127.0.0.1:8765/callback`). The authorization server must be able to fetch the document.

The client only uses this URL if the authorization server supports these documents. Otherwise, it registers. A client ID that you pass with `setClientCredentials()` takes precedence.

## Running Without a User

A background job has no user who can approve a request. Use `forServiceAccount()` with a client ID and a secret that an administrator created for you:

```php
use Mcp\Client\Auth\OAuth;

$auth = OAuth::forServiceAccount('Acme Report Job', 'my-client-id', 'my-client-secret')
    ->setExpectedIssuer('https://auth.example.com')
    ->build();
```

The client then authenticates as itself with the client credentials grant. It doesn't open a browser and doesn't register. If you pass no secret, you must call `setPrivateKeyJwt()`.

Also call `setScopes()`. Nobody approves what a service account requests, so the client then never asks for more than these scopes, even if a server answers `insufficient_scope` for others. It also doesn't reuse a stored token that holds more, for example one from an earlier configuration. Without `setScopes()`, it requests what the server names. The same applies to cross-app access.

## Authenticating With a Private Key

An authorization server can accept a signed assertion in place of a shared secret. This needs the `firebase/php-jwt` package:

```bash
composer require firebase/php-jwt
```

Pass your key with `setPrivateKeyJwt()`:

```php
// the default algorithm is RS256
$auth = OAuth::forServiceAccount('Acme Report Job', 'my-client-id')
    ->setPrivateKeyJwt(file_get_contents('/etc/acme/private-key.pem'), 'ES256')
    ->setExpectedIssuer('https://auth.example.com')
    ->build();
```

The algorithm can be `RS256`, `RS384`, `RS512`, `ES256`, `ES256K` or `ES384`. Use one that the authorization server lists in `token_endpoint_auth_signing_alg_values_supported`. You can also use a key for a user-facing client, together with `setClientCredentials()`.

## Using an Enterprise Identity

!!! warning "Experimental"
    Cross-app access is based on a draft specification. It has only been tested against the MCP conformance suite, not against a real identity provider yet, so its API may still change.

If your users already signed in at a company identity provider, they don't need to approve each MCP server. With cross-app access, the client exchanges their identity token for a grant for one MCP server. It then gets the access token with this grant:

```php
use Mcp\Client\Auth\CrossAppAccess;
use Mcp\Client\Auth\OAuth;

$auth = OAuth::forApplication('My App')
    ->setClientCredentials('my-client-id', 'my-client-secret')
    ->setExpectedIssuer('https://auth.example.com')
    ->setCrossAppAccess(new CrossAppAccess('https://idp.example.com/token', $identityToken))
    ->build();
```

Your application gets `$identityToken` from its own sign-in. Identity tokens expire quickly, so a client that runs for a long time can pass a closure instead. The client calls it each time it needs a new grant:

```php
new CrossAppAccess('https://idp.example.com/token', fn (): string => $session->getIdToken());
```

The token endpoint of the identity provider must use `https`, unless it runs on a loopback address. By default, the client sends the same client ID to the identity provider and no secret. If the identity provider expects other credentials, pass the `clientId` and `clientSecret` arguments of `CrossAppAccess`.

## Choosing Scopes

By default, the client requests the scopes of the challenge. If there are none, it requests all scopes in `scopes_supported` of the resource. If there are none either, it sends no `scope` parameter. Use `setScopes()` to request your own:

```php
OAuth::forApplication('My App')->setScopes('mcp:read', 'mcp:write');
```

If the server answers with `insufficient_scope`, the client adds the scopes of this challenge and authorizes again. It doesn't authorize again for scopes that it already requested. With any other `403`, the client doesn't authorize again.

If the authorization server supports `offline_access`, the client requests it to get a refresh token. If the server refuses this scope, the client tries again without it. Use `setOfflineAccess(false)` to never request this scope. The server can still issue a refresh token, and the client then uses it.

## Connecting to Older Servers

Since the `2025-06-18` revision, a protected server must publish protected resource metadata. By default, the client refuses a server without it. To connect to a server built for the `2025-03-26` revision, call `setLegacyDiscovery(true)`.

## Authenticating Other Requests

To send other requests to the same server with the same credentials, wrap your PSR-18 client:

```php
use Mcp\Client\Auth\AuthenticatingHttpClient;

$httpClient = new AuthenticatingHttpClient($yourPsr18Client, $auth);
```

The token is only added to requests for this server, but a redirect receives the same headers. This class and `OAuth` turn off redirects on a `symfony/http-client` client. Before `symfony/http-client` 6.2, create that client with `max_redirects` set to `0`. Any other client *must not* follow redirects.

It authorizes at most 3 times per request. If the request body isn't seekable, it doesn't send the request again and returns the rejected response.

## What the Client Refuses

The client treats the MCP server and its metadata as untrusted input. It stops with an `AuthorizationException` in these cases:

| Input | Reason |
| --- | --- |
| The protected resource metadata names a `resource` that doesn't cover the endpoint | The token would be issued for another server |
| The authorization server metadata names another `issuer` than the one it was fetched for | The document describes another server |
| The MCP server URL, an authorization server URL or an endpoint doesn't use `https` on a host that isn't loopback | Credentials would travel without encryption |
| One of these URLs names `0.0.0.0`, `::`, an IPv4 address within IPv6 or an IPv4 address in another form than four decimal numbers (e.g. `127.1`), or writes its host with percent-encoding or non-ASCII characters | The request could reach a local service or a private network without looking like it |
| A server that isn't on loopback names a loopback authorization server or endpoint, unless `setExpectedIssuer()` allows that issuer | It could reach services that only listen on this machine |
| The authorization server metadata doesn't list `S256` in `code_challenge_methods_supported` | The client can't use PKCE with this server |
| The authorization endpoint is on another origin than the issuer and the metadata doesn't announce `iss`, without `setExpectedIssuer()` | A response could come from another server, and the code would go to this one |
| The authorization response has no `state`, or another `state` | It doesn't answer the request of this client |
| `iss` doesn't match the issuer, or is missing although the server announced it | The response may come from another server |
| The token response has another `token_type` than `Bearer` | The client can only send bearer tokens |
| The authorization server has another issuer than `setExpectedIssuer()` | It would receive the client's credentials |
| With `setLegacyDiscovery(true)`, a metadata location answers anything but a `404` and gives no usable metadata | The client only uses the fixed endpoints after a `404` |
| The server has no protected resource metadata, without `setLegacyDiscovery(true)` | There is nothing to check the authorization server against |

These checks only look at how a URL writes its host. A DNS name that points at loopback or a private network still gets through. If you run the client on a server, block requests to private networks in your HTTP client or proxy (e.g. with Symfony's `NoPrivateNetworkHttpClient`) and pass that client to `setHttpClient()`. Pass it to `HeadlessAuthorizationHandler` as well, which sends its own request to the authorization endpoint.

!!! warning
    A client that you pass to `setHttpClient()` *must not* follow redirects. The client only turns them off on a `Psr18Client` of `symfony/http-client` 6.2 or later. With redirects on, an authorization server can send the authorization code and the client credentials to any address.

The client ignores a `resource_metadata` URL on another origin than the MCP server and looks at the well-known URLs instead. It only sends a token to the resource it was issued for.

A `resource` can name a parent path of the MCP server. All paths below it then belong to the resource, and the client can send the token to them. If several servers share a host, give each one its own host (e.g. `tenant-a.example.com`).

!!! warning
    The client can't protect a user who approves a hostile authorization server. A hostile MCP server can name one, and the user sees its address in the browser. Tell your users to check it before they sign in.

## Handling Errors

`connect()` reports a failed authorization as a `ConnectionException`. The cause is an `AuthorizationException`:

```php
use Mcp\Exception\AuthorizationException;
use Mcp\Exception\ConnectionException;

try {
    $client->connect($transport);
} catch (ConnectionException $e) {
    if ($e->getPrevious() instanceof AuthorizationException) {
        echo "Could not authorize: {$e->getPrevious()->getMessage()}\n";
    }
}
```

An `AuthorizationPendingException` is never wrapped, see [Finishing the Authorization in a Later Request](#finishing-the-authorization-in-a-later-request).

After `connect()`, requests throw the `AuthorizationException` directly. The client doesn't retry a failed authorization. If the token endpoint is unavailable during a refresh, the request fails and the client keeps the refresh token. Pass a PSR-3 logger with `setLogger()` to see each step.
