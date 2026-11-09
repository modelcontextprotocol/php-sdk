<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Auth;

use Http\Discovery\Psr17FactoryDiscovery;
use Mcp\Client\Transport\NoRedirects;
use Mcp\Exception\AuthorizationException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Requests the authorization endpoint itself and reads the code from the redirect, for servers that grant without a prompt.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class HeadlessAuthorizationHandler implements AuthorizationHandlerInterface
{
    private readonly ClientInterface $httpClient;
    private readonly RequestFactoryInterface $requestFactory;

    /**
     * @param ?ClientInterface $httpClient the client for the request, which must not follow redirects,
     *                                     so pass the one given to OAuth::setHttpClient()
     */
    public function __construct(
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->httpClient = null === $httpClient ? NoRedirects::discover() : NoRedirects::apply($httpClient);
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
    }

    public function authorize(string $authorizationUrl, string $redirectUri): array
    {
        $location = $this->fetchRedirect($authorizationUrl);
        $query = parse_url($location, \PHP_URL_QUERY);

        if (!\is_string($query) || '' === $query) {
            throw new AuthorizationException(\sprintf('The authorization server redirected to "%s", which carries no query parameters.', $location));
        }

        parse_str($query, $parameters);

        $callback = [];

        foreach ($parameters as $name => $value) {
            if (\is_string($name) && \is_scalar($value)) {
                $callback[$name] = (string) $value;
            }
        }

        // Only the names, as the query carries the authorization code.
        $this->logger->debug('Authorization endpoint redirected', ['parameters' => array_keys($callback)]);

        return $callback;
    }

    private function fetchRedirect(string $url): string
    {
        try {
            $response = $this->httpClient->sendRequest($this->requestFactory->createRequest('GET', $url));
        } catch (ClientExceptionInterface $e) {
            throw new AuthorizationException(\sprintf('Could not reach the authorization endpoint at "%s": %s', $url, $e->getMessage()), 0, $e);
        }

        $response->getBody()->close();
        $location = $response->getHeaderLine('Location');

        if ($response->getStatusCode() < 300 || $response->getStatusCode() >= 400 || '' === $location) {
            throw new AuthorizationException(\sprintf('The authorization endpoint "%s" did not answer with a redirect. It may need a user to approve the request, in which case an interactive authorization handler is required.', $url));
        }

        return $location;
    }
}
