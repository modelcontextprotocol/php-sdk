<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Client\Auth;

use Mcp\Client\Auth\AccessToken;
use Mcp\Client\Auth\ClientRegistration;
use Mcp\Client\Auth\FileCredentialStorage;
use Mcp\Client\Auth\TokenEndpointAuthMethod;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class FileCredentialStorageTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/mcp-credentials-'.bin2hex(random_bytes(6)).'/credentials.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
            rmdir(\dirname($this->path));
        }
    }

    #[TestDox('credentials written by one run are found by the next')]
    public function testSurvivesAcrossInstances(): void
    {
        $storage = new FileCredentialStorage($this->path);
        $storage->saveClientRegistration('https://auth.example.com', new ClientRegistration('client', 'secret', TokenEndpointAuthMethod::ClientSecretBasic, true));
        $storage->saveToken('https://auth.example.com', 'https://mcp.example.com/mcp', new AccessToken('token', 'Bearer', 4600, 'refresh', ['mcp:read']));

        $reopened = new FileCredentialStorage($this->path);

        $registration = $reopened->getClientRegistration('https://auth.example.com');
        $token = $reopened->getToken('https://auth.example.com', 'https://mcp.example.com/mcp');

        $this->assertNotNull($registration);
        $this->assertNotNull($token);
        $this->assertSame('client', $registration->clientId);
        $this->assertSame(TokenEndpointAuthMethod::ClientSecretBasic, $registration->tokenEndpointAuthMethod);
        $this->assertSame('token', $token->accessToken);
        $this->assertSame(['mcp:read'], $token->scopes);
    }

    #[TestDox('tokens for one resource are not handed out for another')]
    public function testKeysTokensByResource(): void
    {
        $storage = new FileCredentialStorage($this->path);
        $storage->saveToken('https://auth.example.com', 'https://mcp.example.com/mcp', new AccessToken('token'));

        $this->assertNull($storage->getToken('https://auth.example.com', 'https://other.example.com/mcp'));
        $this->assertNull($storage->getToken('https://other-auth.example.com', 'https://mcp.example.com/mcp'));
    }

    #[TestDox('forgetting one authorization server leaves the others alone')]
    public function testForgetsOneIssuer(): void
    {
        $storage = new FileCredentialStorage($this->path);
        $storage->saveToken('https://one.example.com', 'https://mcp.example.com/mcp', new AccessToken('one'));
        $storage->saveToken('https://two.example.com', 'https://mcp.example.com/mcp', new AccessToken('two'));

        $storage->forget('https://one.example.com');

        $this->assertNull($storage->getToken('https://one.example.com', 'https://mcp.example.com/mcp'));
        $this->assertSame('two', $storage->getToken('https://two.example.com', 'https://mcp.example.com/mcp')?->accessToken);
    }

    #[TestDox('the file is not readable by anyone else')]
    public function testFilePermissions(): void
    {
        $storage = new FileCredentialStorage($this->path);
        $storage->saveToken('https://auth.example.com', 'https://mcp.example.com/mcp', new AccessToken('token'));

        $this->assertSame('0600', substr(\sprintf('%o', fileperms($this->path)), -4));
    }

    #[TestDox('a corrupted file costs one authorization, not a crash')]
    public function testToleratesACorruptedFile(): void
    {
        @mkdir(\dirname($this->path), 0700, true);
        file_put_contents($this->path, 'not json at all');

        $this->assertNull((new FileCredentialStorage($this->path))->getToken('https://auth.example.com', 'https://mcp.example.com/mcp'));
    }

    #[TestDox('nothing is stored until there is something to store')]
    public function testDoesNotTouchTheFileSystemWhenIdle(): void
    {
        $storage = new FileCredentialStorage($this->path);

        $this->assertNull($storage->getToken('https://auth.example.com', 'https://mcp.example.com/mcp'));
        $this->assertFileDoesNotExist($this->path);
    }
}
