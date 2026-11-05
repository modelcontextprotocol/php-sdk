<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * A minimal but honest OAuth 2.1 authorization server, run under `php -S`.
 *
 * Honest in the ways the client can tell apart: it publishes RFC 8414 metadata,
 * registers clients dynamically, enforces PKCE, and only issues a token for a code it
 * actually handed out. It approves without asking a user, which is the one thing a real
 * server would not do -- that is what the Keycloak end-to-end setup covers instead.
 *
 * State lives in the file named by MCP_OAUTH_STATE because the built-in server starts a
 * fresh PHP process per request, and the resource server needs to see the tokens too.
 */

$state = new class(getenv('MCP_OAUTH_STATE') ?: '') {
    public function __construct(private readonly string $path)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $contents = is_file($this->path) ? file_get_contents($this->path) : false;

        return false === $contents ? [] : (json_decode($contents, true) ?: []);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function merge(array $values): void
    {
        file_put_contents($this->path, json_encode(array_merge($this->read(), $values)), \LOCK_EX);
    }
};

$issuer = 'http://'.($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH);

$json = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
};

$body = static function (): array {
    $raw = file_get_contents('php://input') ?: '';

    if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        return json_decode($raw, true) ?: [];
    }

    parse_str($raw, $form);

    return $form;
};

switch ($path) {
    case '/.well-known/oauth-authorization-server':
        $json([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/authorize',
            'token_endpoint' => $issuer.'/token',
            'registration_endpoint' => $issuer.'/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'none'],
            'scopes_supported' => ['mcp:read', 'offline_access'],
            'authorization_response_iss_parameter_supported' => true,
        ]);
        break;

    case '/register':
        $registration = $body();
        $state->merge([
            'client_id' => 'dynamic-client',
            'client_secret' => 'dynamic-secret',
            'registration' => $registration,
        ]);

        $json([
            'client_id' => 'dynamic-client',
            'client_secret' => 'dynamic-secret',
            'client_name' => $registration['client_name'] ?? null,
            'redirect_uris' => $registration['redirect_uris'] ?? [],
            'token_endpoint_auth_method' => 'client_secret_basic',
        ], 201);
        break;

    case '/authorize':
        $state->merge([
            'code_challenge' => $_GET['code_challenge'] ?? null,
            'granted_scope' => $_GET['scope'] ?? '',
            'authorization_query' => $_GET,
            // Counted so a test can tell one failed authorization from a client that
            // quietly went round again.
            'authorization_count' => ($state->read()['authorization_count'] ?? 0) + 1,
        ]);

        $redirect = $_GET['redirect_uri'].'?'.http_build_query(array_filter([
            'code' => 'integration-authorization-code',
            'state' => $_GET['state'] ?? null,
            'iss' => $issuer,
        ]));

        http_response_code(302);
        header('Location: '.$redirect);
        break;

    case '/token':
        $form = $body();
        $stored = $state->read();

        // Set by a test that wants to see what the client does when the exchange fails.
        if ($stored['fail_token'] ?? false) {
            $json(['error' => 'invalid_client', 'error_description' => 'Refused on purpose.'], 401);
            break;
        }

        if ('integration-authorization-code' !== ($form['code'] ?? null)) {
            $json(['error' => 'invalid_grant'], 400);
            break;
        }

        $challenge = rtrim(strtr(base64_encode(hash('sha256', (string) ($form['code_verifier'] ?? ''), true)), '+/', '-_'), '=');

        if ($challenge !== ($stored['code_challenge'] ?? null)) {
            $json(['error' => 'invalid_grant', 'error_description' => 'PKCE verification failed'], 400);
            break;
        }

        $token = 'integration-access-token-'.bin2hex(random_bytes(4));
        $state->merge([
            'issued_token' => $token,
            'token_request' => $form,
            'token_authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        ]);

        $json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => 'integration-refresh-token',
            'scope' => $stored['granted_scope'] ?? '',
        ]);
        break;

    default:
        $json(['error' => 'not_found'], 404);
}
