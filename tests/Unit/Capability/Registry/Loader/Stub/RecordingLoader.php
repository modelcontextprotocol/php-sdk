<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Registry\Loader\Stub;

use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\RegistryInterface;

final class RecordingLoader implements LoaderInterface
{
    /**
     * @param \ArrayObject<int, string> $calls
     */
    public function __construct(private string $name, private \ArrayObject $calls)
    {
    }

    public function load(RegistryInterface $registry): void
    {
        $this->calls->append($this->name);
    }
}
