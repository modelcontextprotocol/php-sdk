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

use Mcp\Exception\RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Does what an administrator does before a service account can sign its own assertions:
 * registers the public half of its key at Keycloak.
 *
 * The key is generated for each run, so no private key is ever committed.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class KeycloakAdmin
{
    private readonly HttpClientInterface $httpClient;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $realm,
        private readonly string $username = 'admin',
        private readonly string $password = 'admin',
    ) {
        $this->httpClient = HttpClient::create(['timeout' => 15]);
    }

    /**
     * @return string the PEM private key whose certificate the client now accepts
     */
    public function issueSigningKey(string $clientId): string
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = false !== $key ? openssl_csr_new(['commonName' => $clientId], $key, ['digest_alg' => 'sha256']) : false;
        $certificate = $csr instanceof \OpenSSLCertificateSigningRequest ? openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']) : false;

        if (false === $key || false === $certificate || !openssl_pkey_export($key, $privateKeyPem) || !openssl_x509_export($certificate, $certificatePem)) {
            throw new RuntimeException('Generating the signing key failed: '.openssl_error_string());
        }

        // Keycloak merges the attributes of a partial update; sending the whole client back fails.
        $this->httpClient->request('PUT', $this->adminUrl('/clients/'.$this->findClientId($clientId)), [
            'auth_bearer' => $this->adminToken(),
            'json' => ['attributes' => ['jwt.credential.certificate' => preg_replace('/-----[A-Z ]+-----|\s/', '', $certificatePem)]],
        ])->getContent();

        return $privateKeyPem;
    }

    private function findClientId(string $clientId): string
    {
        $clients = $this->httpClient->request('GET', $this->adminUrl('/clients'), [
            'auth_bearer' => $this->adminToken(),
            'query' => ['clientId' => $clientId],
        ])->toArray();

        if (!isset($clients[0]['id'])) {
            throw new RuntimeException(\sprintf('The realm "%s" has no client "%s".', $this->realm, $clientId));
        }

        return $clients[0]['id'];
    }

    private function adminToken(): string
    {
        return $this->httpClient->request('POST', $this->baseUrl.'/realms/master/protocol/openid-connect/token', [
            'body' => ['grant_type' => 'password', 'client_id' => 'admin-cli', 'username' => $this->username, 'password' => $this->password],
        ])->toArray()['access_token'];
    }

    private function adminUrl(string $path): string
    {
        return $this->baseUrl.'/admin/realms/'.$this->realm.$path;
    }
}
