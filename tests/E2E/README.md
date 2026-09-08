# End-to-end authorization

The conformance suite proves the client follows the specification. This proves it works.

Everything here is real: [Keycloak](https://www.keycloak.org/) as the authorization
server, an MCP server built with this SDK validating Keycloak's own signed tokens behind
the SDK's authorization middleware, and a client that starts with no credentials at all
and has to find its way in from a bare `401`. Nothing is stubbed and nothing is
in-process — the client talks to Keycloak and to the MCP server over HTTP, the way an
application would.

```bash
tests/E2E/run.sh
```

Needs Docker and a `composer install`. The first run pulls the Keycloak image, which
takes a minute; afterwards the whole thing is about twenty seconds.

## What it checks

```
  ok   a client with no credentials is refused
  ok   the protected tool list is readable
  ok   the protected tool answers
  ok   the credentials were written to disk
  ok   the stored token still opens the server
```

Between the first and second line the client does all of this against a real identity
provider, unattended:

1. Reads the `WWW-Authenticate` challenge and fetches the protected resource metadata.
2. Discovers Keycloak's OpenID Connect configuration from the issuer the metadata names,
   and checks the document really describes that issuer.
3. Starts the authorization code flow with PKCE, naming the MCP server as the resource.
4. Signs `demo` in at Keycloak's login form (see `BrowserAuthorizationHandler`, which
   plays the browser — it is a test fixture, not part of the SDK).
5. Verifies `state` and `iss` on the redirect, and exchanges the code for a token.
6. Presents the token; the MCP server verifies its signature against Keycloak's published
   keys, and its audience against the mapper in the realm.
7. Writes the token to disk, so the second connection needs no browser.

## Layout

| File | What it is |
| --- | --- |
| `docker-compose.yml` | Keycloak plus the MCP server, on ports of their own so the OAuth server example can stay up alongside |
| `server.php` | The MCP server: SDK authorization middleware, SDK HTTP transport, real JWT validation |
| `client.php` | The run itself — the assertions are here |
| `BrowserAuthorizationHandler.php` | Signs the user in at a real login form, so no human is needed |
| `nginx.conf` | Routes `/mcp` and the well-known paths to `server.php` |

The Keycloak realm is the one
[`examples/server/oauth-keycloak`](../../examples/server/oauth-keycloak) already ships:
one public client with loopback redirect URIs, one `demo` / `demo123` user, and a client
scope whose audience mapper stamps the MCP server into the token.

## In continuous integration

`run.sh` is the whole entry point, so the `e2e` job in
[`pipeline.yaml`](../../.github/workflows/pipeline.yaml) is one step.

## Running it by hand

```bash
E2E_KEEP=1 tests/E2E/run.sh          # leave the containers up afterwards
E2E_KEYCLOAK_PORT=9181 tests/E2E/run.sh   # move the ports if something holds the defaults
docker compose -f tests/E2E/docker-compose.yml logs   # when something goes wrong
```

Keycloak's admin console is at <http://localhost:8181/admin> (`admin` / `admin`) while
the environment is up.
