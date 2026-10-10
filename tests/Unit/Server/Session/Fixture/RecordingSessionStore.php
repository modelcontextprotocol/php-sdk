<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Session\Fixture;

use Mcp\Server\Session\InMemorySessionStore;
use Symfony\Component\Uid\Uuid;

/**
 * A session store that records its reads and writes, next to the lock's calls, in one shared log.
 */
final class RecordingSessionStore extends InMemorySessionStore
{
    /**
     * @param list<string> $log receives "read <id>", "write <id>" and "destroy <id>" entries
     */
    public function __construct(public array &$log)
    {
        parent::__construct();
    }

    public function read(Uuid $id): string|false
    {
        $this->log[] = 'read '.$id->toRfc4122();

        return parent::read($id);
    }

    public function write(Uuid $id, string $data): bool
    {
        $this->log[] = 'write '.$id->toRfc4122();

        return parent::write($id, $data);
    }

    public function destroy(Uuid $id): bool
    {
        $this->log[] = 'destroy '.$id->toRfc4122();

        return parent::destroy($id);
    }
}
