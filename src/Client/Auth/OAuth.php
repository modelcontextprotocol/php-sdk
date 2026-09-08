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
use Http\Discovery\Psr18ClientDiscovery;
use Mcp\Exception\InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Fluent builder for the OAuth authenticator an {@see \Mcp\Client\Transport\HttpTransport} uses.
 *
 * The defaults describe the common case -- a local application, a user with a browser,
 * an authorization server that supports dynamic client registration -- so a working
 * setup is one call:
 *
 *     $transport = new HttpTransport($url, auth: OAuth::forApplication('My App')->build());
 *
 * Everything else is a deviation from that: a pre-registered client id, a machine
 * account with no user, credentials that should outlive the process.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class OAuth
{
    /**
     * Loopback redirect target used unless the application names its own.
     *
     * A fixed port because many authorization servers require redirect URIs to be
     * registered up front, and a random one could never be.
     */
    public const DEFAULT_REDIRECT_URI = 'http://127.0.0.1:8765/callback';

    private string $redirectUri = self::DEFAULT_REDIRECT_URI;
    private Grant $grant = Grant::AuthorizationCode;
    private ?string $clientId = null;
    private ?string $clientSecret = null;
    private ?string $clientMetadataUrl = null;
    private ?string $privateKeyPem = null;
    private string $signingAlgorithm = 'RS256';
    private string $applicationType = 'native';
    private ?string $clientUri = null;
    private ?string $softwareId = null;
    private ?string $softwareVersion = null;

    /** @var string[] */
    private array $scopes = [];
    private bool $offlineAccess = true;
    private bool $legacyDiscovery = false;
    private ?string $resource = null;
    private ?CrossAppAccess $crossAppAccess = null;

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
     * A client acting as itself, with no user and no browser (RFC 6749 section 4.4).
     *
     * The credentials have to be issued out of band: there is nobody to consent to a
     * dynamic registration.
     */
    public static function forServiceAccount(string $clientName, string $clientId, ?string $clientSecret = null): self
    {
        $oauth = new self($clientName);
        $oauth->grant = Grant::ClientCredentials;
        $oauth->clientId = $clientId;
        $oauth->clientSecret = $clientSecret;
        $oauth->offlineAccess = false;

        return $oauth;
    }

    /**
     * Where the authorization server sends the user back to.
     *
     * Must match what {@see LoopbackAuthorizationHandler} listens on, and -- for a
     * pre-registered client -- what the authorization server has on file.
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
     * Publish the client's identity at a URL and use that URL as the client id.
     *
     * Servers that support client id metadata documents read the client's metadata from
     * this URL, so one identity works everywhere instead of one registration per server.
     * The document must be reachable by the authorization server.
     */
    public function setClientMetadataUrl(string $url): self
    {
        $this->clientMetadataUrl = $url;

        return $this;
    }

    /**
     * Authenticate at the token endpoint with a signed assertion instead of a secret.
     *
     * Needs the firebase/php-jwt package, which this SDK suggests rather than requires:
     * every other way of authenticating a client works without it.
     *
     * @param string $privateKeyPem a PEM-encoded private key
     * @param string $algorithm     one of RS256, RS384, RS512, PS256, ES256, ES256K,
     *                              ES384 or EdDSA -- whichever the authorization server
     *                              advertises in token_endpoint_auth_signing_alg_values_supported
     */
    public function setPrivateKeyJwt(string $privateKeyPem, string $algorithm = 'RS256'): self
    {
        $this->privateKeyPem = $privateKeyPem;
        $this->signingAlgorithm = $algorithm;

        return $this;
    }

    /**
     * Ask for exactly these scopes, rather than whatever the server asks for.
     */
    public function setScopes(string ...$scopes): self
    {
        $this->scopes = array_values($scopes);

        return $this;
    }

    /**
     * Whether to ask for a refresh token where the authorization server offers one.
     *
     * On by default: without it every expiry sends the user back to the browser.
     */
    public function setOfflineAccess(bool $offlineAccess): self
    {
        $this->offlineAccess = $offlineAccess;

        return $this;
    }

    /**
     * Whether to fall back to pre-2025-06-18 discovery for servers that publish no
     * protected resource metadata.
     *
     * Off by default. Every revision of the specification since 2025-06-18 requires a
     * protected resource server to publish that document, so a server that does not is
     * either much older than this SDK or is not the server it appears to be -- and
     * without the document there is nothing to check the authorization server against.
     * Turn it on knowingly, to reach a server built against 2025-03-26.
     */
    public function setLegacyDiscovery(bool $legacyDiscovery): self
    {
        $this->legacyDiscovery = $legacyDiscovery;

        return $this;
    }

    /**
     * Request tokens for this resource identifier instead of the endpoint being called.
     */
    public function setResource(string $resource): self
    {
        $this->resource = $resource;

        return $this;
    }

    /**
     * Reach the server through an identity the user already holds at their identity
     * provider, rather than sending them to a consent screen.
     *
     * Requires the client id and secret the authorization server knows this client by,
     * set with {@see self::setClientCredentials()}.
     */
    public function setCrossAppAccess(CrossAppAccess $crossAppAccess): self
    {
        $this->crossAppAccess = $crossAppAccess;
        $this->grant = Grant::JwtBearer;
        $this->offlineAccess = false;

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
        $httpClient = $this->httpClient ?? Psr18ClientDiscovery::find();
        $requestFactory = $this->requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $streamFactory = $this->streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();

        $grantTypes = match (true) {
            Grant::AuthorizationCode !== $this->grant => [$this->grant->value],
            $this->offlineAccess => [Grant::AuthorizationCode->value, Grant::RefreshToken->value],
            default => [Grant::AuthorizationCode->value],
        };

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
            $this->grant,
            $this->clientId,
            $this->clientSecret,
            $this->clientMetadataUrl,
            $this->privateKeyPem,
            $this->signingAlgorithm,
            $this->scopes,
            $this->offlineAccess,
            $this->legacyDiscovery,
            $this->resource,
            $this->crossAppAccess,
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
