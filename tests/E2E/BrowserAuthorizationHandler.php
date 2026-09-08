<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\E2E;

use Mcp\Client\Auth\AuthorizationHandlerInterface;
use Mcp\Exception\AuthorizationException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Signs a user in the way a browser would, so the end-to-end run needs no human.
 *
 * The SDK's own {@see \Mcp\Client\Auth\HeadlessAuthorizationHandler} stops at the first
 * response, which is all a permissive test server needs. A real identity provider
 * answers with a login page instead, so this walks the rest of the way: it renders
 * nothing, but it does what the rendered page would -- carry the session cookie back,
 * post the credentials to the form's action, and read the redirect that follows.
 *
 * It lives in the test suite rather than in the SDK on purpose. Automating a login form
 * means holding a user's password, and no library should encourage that.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class BrowserAuthorizationHandler implements AuthorizationHandlerInterface
{
    private readonly HttpClientInterface $httpClient;

    /** @var array<string, string> */
    private array $cookies = [];

    public function __construct(
        private readonly string $username,
        private readonly string $password,
    ) {
        // Redirects are the payload here, not a detour to be followed.
        $this->httpClient = HttpClient::create(['max_redirects' => 0]);
    }

    public function authorize(string $authorizationUrl, string $redirectUri): array
    {
        [$status, $headers, $body] = $this->get($authorizationUrl);

        if (302 === $status || 303 === $status) {
            // Already signed in, or an authorization server that never asks.
            return self::parametersOf($headers['location'][0] ?? '');
        }

        if (200 !== $status) {
            throw new AuthorizationException(\sprintf('The authorization endpoint answered %d instead of a login page.', $status));
        }

        [$status, $headers] = $this->submitLoginForm($body);

        if (302 !== $status && 303 !== $status) {
            throw new AuthorizationException(\sprintf('Signing in as "%s" did not produce a redirect (got %d). The credentials or the login form may have changed.', $this->username, $status));
        }

        return self::parametersOf($headers['location'][0] ?? '');
    }

    /**
     * @return array{int, array<string, string[]>}
     */
    private function submitLoginForm(string $page): array
    {
        if (!preg_match('/<form[^>]+action="([^"]+)"/i', $page, $matches)) {
            throw new AuthorizationException('The authorization endpoint returned a page with no login form.');
        }

        $response = $this->httpClient->request('POST', html_entity_decode($matches[1], \ENT_QUOTES | \ENT_HTML5), [
            'headers' => ['Cookie' => $this->cookieHeader()],
            'body' => ['username' => $this->username, 'password' => $this->password],
        ]);

        return [$response->getStatusCode(), $response->getHeaders(false)];
    }

    /**
     * @return array{int, array<string, string[]>, string}
     */
    private function get(string $url): array
    {
        $response = $this->httpClient->request('GET', $url, ['headers' => ['Cookie' => $this->cookieHeader()]]);
        $headers = $response->getHeaders(false);

        foreach ($headers['set-cookie'] ?? [] as $cookie) {
            [$pair] = explode(';', $cookie, 2);
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $this->cookies[trim($name)] = trim($value);
        }

        return [$response->getStatusCode(), $headers, $response->getContent(false)];
    }

    private function cookieHeader(): string
    {
        return implode('; ', array_map(
            static fn (string $name, string $value): string => $name.'='.$value,
            array_keys($this->cookies),
            $this->cookies,
        ));
    }

    /**
     * @return array<string, string>
     */
    private static function parametersOf(string $location): array
    {
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $parameters);

        if ([] === $parameters) {
            throw new AuthorizationException(\sprintf('The authorization server redirected to "%s", which carries nothing to continue with.', $location));
        }

        return array_map(strval(...), array_filter($parameters, is_scalar(...)));
    }
}
