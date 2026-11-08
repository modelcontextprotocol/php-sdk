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
        }

        $lock = \dirname($this->path).'/.'.basename($this->path).'.lock';

        if (is_file($lock)) {
            unlink($lock);
        }

        if (is_dir(\dirname($this->path))) {
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
        if ('Windows' === \PHP_OS_FAMILY) {
            $this->markTestSkipped('Windows has no POSIX file modes.');
        }

        $storage = new FileCredentialStorage($this->path);
        $storage->saveToken('https://auth.example.com', 'https://mcp.example.com/mcp', new AccessToken('token'));

        $this->assertSame('0600', substr(\sprintf('%o', fileperms($this->path)), -4));
    }

    // The window between creating a file and chmod'ing it is a window in which another
    // local user can open it, so the write happens in a private file that is then moved
    // into place.
    #[TestDox('the credentials are never world-readable, not even briefly')]
    public function testFileIsNeverExposedWhileBeingWritten(): void
    {
        if ('Windows' === \PHP_OS_FAMILY) {
            $this->markTestSkipped('Windows has no POSIX file modes.');
        }

        $storage = new FileCredentialStorage($this->path);
        $storage->saveToken('https://auth.example.com', 'https://mcp.example.com/mcp', new AccessToken('first'));

        clearstatcache();
        $before = fileperms($this->path);

        $storage->saveToken('https://auth.example.com', 'https://mcp.example.com/mcp', new AccessToken('second'));

        clearstatcache();
        $this->assertSame($before, fileperms($this->path));
        $this->assertSame('0600', substr(\sprintf('%o', fileperms($this->path)), -4));
        $this->assertSame('second', $storage->getToken('https://auth.example.com', 'https://mcp.example.com/mcp')?->accessToken);
        $this->assertCount(1, glob(\dirname($this->path).'/*') ?: [], 'The temporary file should not survive the write.');
    }

    #[TestDox('a corrupted file costs one authorization, not a crash')]
    public function testToleratesACorruptedFile(): void
    {
        @mkdir(\dirname($this->path), 0700, true);
        file_put_contents($this->path, 'not json at all');

        $this->assertNull((new FileCredentialStorage($this->path))->getToken('https://auth.example.com', 'https://mcp.example.com/mcp'));
    }

    #[TestDox('an entry of the wrong type costs one authorization, not a crash')]
    public function testToleratesAnEntryOfTheWrongType(): void
    {
        @mkdir(\dirname($this->path), 0700, true);
        file_put_contents($this->path, (string) json_encode([
            'registrations' => ['https://auth.example.com' => 'not an array'],
            'tokens' => ['https://auth.example.com' => ['https://mcp.example.com/mcp' => 'not an array']],
        ]));

        $storage = new FileCredentialStorage($this->path);

        $this->assertNull($storage->getClientRegistration('https://auth.example.com'));
        $this->assertNull($storage->getToken('https://auth.example.com', 'https://mcp.example.com/mcp'));
    }

    #[TestDox('two instances on one file do not overwrite each other')]
    public function testInstancesDoNotLoseEachOthersUpdates(): void
    {
        $first = new FileCredentialStorage($this->path);
        $second = new FileCredentialStorage($this->path);

        $this->assertNull($second->getToken('https://auth.example.com', 'https://one.example.com/mcp'));

        $first->saveToken('https://auth.example.com', 'https://one.example.com/mcp', new AccessToken('one'));
        $second->saveToken('https://auth.example.com', 'https://two.example.com/mcp', new AccessToken('two'));

        $this->assertSame('one', $first->getToken('https://auth.example.com', 'https://one.example.com/mcp')?->accessToken);
        $this->assertSame('two', $first->getToken('https://auth.example.com', 'https://two.example.com/mcp')?->accessToken);
    }

    #[TestDox('the lock file is as private as the credentials')]
    public function testLockFilePermissions(): void
    {
        if ('Windows' === \PHP_OS_FAMILY) {
            $this->markTestSkipped('Windows has no POSIX file modes.');
        }

        (new FileCredentialStorage($this->path))->saveToken('https://auth.example.com', 'https://mcp.example.com/mcp', new AccessToken('one'));

        clearstatcache();
        $this->assertSame('0600', substr(\sprintf('%o', fileperms(\dirname($this->path).'/.'.basename($this->path).'.lock')), -4));
    }

    #[TestDox('an entry of the wrong type does not stop a new token from being saved')]
    public function testSavesOverAnEntryOfTheWrongType(): void
    {
        @mkdir(\dirname($this->path), 0700, true);
        file_put_contents($this->path, (string) json_encode(['tokens' => ['https://auth.example.com' => 'not an array']]));

        $storage = new FileCredentialStorage($this->path);
        $storage->saveToken('https://auth.example.com', 'https://mcp.example.com/mcp', new AccessToken('token'));

        $this->assertSame('token', $storage->getToken('https://auth.example.com', 'https://mcp.example.com/mcp')?->accessToken);
    }

    #[TestDox('nothing is stored until there is something to store')]
    public function testDoesNotTouchTheFileSystemWhenIdle(): void
    {
        $storage = new FileCredentialStorage($this->path);

        $this->assertNull($storage->getToken('https://auth.example.com', 'https://mcp.example.com/mcp'));
        $this->assertFileDoesNotExist($this->path);
    }
}
