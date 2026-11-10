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
use Mcp\Exception\InvalidArgumentException;
use Mcp\Server\Transport\Http\OAuth\SecureUrl;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Fluent builder for the OAuth authenticator of an {@see \Mcp\Client\Transport\HttpTransport}.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class OAuth
{
    /**
     * A fixed port, as many authorization servers require redirect URIs to be registered up front.
     */
    public const DEFAULT_REDIRECT_URI = 'http://127.0.0.1:8765/callback';

    private string $redirectUri = self::DEFAULT_REDIRECT_URI;
    private ?string $clientId = null;
    private ?string $clientSecret = null;
    private ?string $expectedIssuer = null;
    private ?string $clientMetadataUrl = null;
    private string $applicationType = 'native';
    private ?string $clientUri = null;
    private ?string $softwareId = null;
    private ?string $softwareVersion = null;

    /** @var string[] */
    private array $scopes = [];
    private bool $offlineAccess = true;
    private bool $legacyDiscovery = false;

    private ?CredentialStorageInterface $storage = null;
    private ?AuthorizationHandlerInterface $authorizationHandler = null;
    private ?ClientInterface $httpClient = null;
    private ?RequestFactoryInterface $requestFactory = null;
    private ?StreamFactoryInterface $streamFactory = null;
    private LoggerInterface $logger;

    private function __construct(private readonly string $clientName)
    {
        if ('' === trim($clientName)) {
            throw new InvalidArgumentException('The client name must not be empty; it is what the user sees on the consent screen.');
        }

        $this->logger = new NullLogger();
    }

    /**
     * An application acting for a user, who approves the request in a browser.
     *
     * @param string $clientName the name shown to the user while they approve
     */
    public static function forApplication(string $clientName): self
    {
        return new self($clientName);
    }

    /**
     * Must match what the authorization handler listens on and, for a pre-registered client, what the server has on file.
     */
    public function setRedirectUri(string $redirectUri): self
    {
        $this->redirectUri = $redirectUri;

        return $this;
    }

    /**
     * Use a client id issued out of band instead of registering dynamically.
     */
    public function setClientCredentials(string $clientId, ?string $clientSecret = null): self
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;

        return $this;
    }

    /**
     * Only authorize with this issuer; required with a configured client, or a hostile MCP server could receive its codes and secret.
     */
    public function setExpectedIssuer(string $issuer): self
    {
        $this->expectedIssuer = $issuer;

        return $this;
    }

    /**
     * Use the URL of a client ID metadata document as the client id where the server supports it.
     */
    public function setClientMetadataUrl(string $url): self
    {
        try {
            $parts = SecureUrl::parse($url, 'client metadata URL');
        } catch (InvalidArgumentException) {
            $parts = null;
        }

        if (null === $parts
            || false === filter_var($url, \FILTER_VALIDATE_URL)
            || 'https' !== strtolower($parts['scheme'])
            || '' === trim($parts['path'] ?? '', '/')
            || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])
            || [] !== array_intersect(explode('/', rawurldecode($parts['path'] ?? '')), ['.', '..'])
        ) {
            throw new InvalidArgumentException(\sprintf('The client metadata URL must be an https URL with a path, and without a fragment, credentials or dot segments, got "%s".', $url));
        }

        $this->clientMetadataUrl = $url;

        return $this;
    }

    /**
     * Request these scopes instead of those the server asks for; a step-up challenge still adds its own.
     */
    public function setScopes(string ...$scopes): self
    {
        $this->scopes = array_values($scopes);

        return $this;
    }

    /**
     * Whether to request a refresh token where the authorization server offers one (default: true).
     */
    public function setOfflineAccess(bool $offlineAccess): self
    {
        $this->offlineAccess = $offlineAccess;

        return $this;
    }

    /**
     * Whether to fall back to pre-2025-06-18 discovery for a server without protected resource metadata (default: false).
     */
    public function setLegacyDiscovery(bool $legacyDiscovery): self
    {
        $this->legacyDiscovery = $legacyDiscovery;

        return $this;
    }

    /**
     * Whether the client runs on the user's machine ("native") or on a server ("web").
     */
    public function setApplicationType(string $applicationType): self
    {
        if (!\in_array($applicationType, ['native', 'web'], true)) {
            throw new InvalidArgumentException(\sprintf('The application type must be "native" or "web", got "%s".', $applicationType));
        }

        $this->applicationType = $applicationType;

        return $this;
    }

    /**
     * Extra identifying information shown to users and administrators.
     */
    public function setSoftwareInfo(?string $clientUri = null, ?string $softwareId = null, ?string $softwareVersion = null): self
    {
        $this->clientUri = $clientUri;
        $this->softwareId = $softwareId;
        $this->softwareVersion = $softwareVersion;

        return $this;
    }

    /**
     * Where tokens and registrations live between requests, and between runs.
     */
    public function setCredentialStorage(CredentialStorageInterface $storage): self
    {
        $this->storage = $storage;

        return $this;
    }

    /**
     * How the user gets to the authorization endpoint and back.
     */
    public function setAuthorizationHandler(AuthorizationHandlerInterface $handler): self
    {
        $this->authorizationHandler = $handler;

        return $this;
    }

    public function setHttpClient(
        ClientInterface $httpClient,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ): self {
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;

        return $this;
    }

    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function build(): OAuthAuthenticator
    {
        $httpClient = null === $this->httpClient ? NoRedirects::discover() : NoRedirects::apply($this->httpClient);
        $requestFactory = $this->requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $streamFactory = $this->streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();

        $grantTypes = $this->offlineAccess
            ? [Grant::AuthorizationCode->value, Grant::RefreshToken->value]
            : [Grant::AuthorizationCode->value];

        $configuration = new OAuthConfiguration(
            new ClientMetadata(
                $this->clientName,
                [$this->redirectUri],
                $grantTypes,
                $this->applicationType,
                $this->clientUri,
                $this->softwareId,
                $this->softwareVersion,
            ),
            $this->redirectUri,
            $this->clientId,
            $this->clientSecret,
            $this->clientMetadataUrl,
            $this->scopes,
            $this->offlineAccess,
            $this->legacyDiscovery,
            $this->expectedIssuer,
        );

        return new OAuthAuthenticator(
            $configuration,
            $this->storage ?? new InMemoryCredentialStorage(),
            $this->authorizationHandler ?? new LoopbackAuthorizationHandler(logger: $this->logger),
            new MetadataDiscovery($httpClient, $requestFactory, $this->logger),
            new ClientRegistrar($httpClient, $requestFactory, $streamFactory, $this->logger),
            new TokenEndpoint($httpClient, $requestFactory, $streamFactory, $this->logger),
            $this->logger,
        );
    }
}
