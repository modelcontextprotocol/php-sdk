<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Fixtures\Runtime;

use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\RuntimeToolHandlerInterface;

final class NullSchemaToolHandler implements RuntimeToolHandlerInterface
{
    public function getInputSchema(): ?array
    {
        return null;
    }

    public function getOutputSchema(): ?array
    {
        return null;
    }

    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        return null;
    }
}
