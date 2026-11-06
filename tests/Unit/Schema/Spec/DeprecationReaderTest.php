<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\Spec;

use Mcp\Tools\SchemaGenerator\DeprecationReader;
use PHPUnit\Framework\TestCase;

final class DeprecationReaderTest extends TestCase
{
    public function testReadsTypeAndPropertyDeprecations(): void
    {
        $typeScript = <<<'TS'
            /**
             * Sent by the client.
             *
             * @deprecated Deprecated as of protocol version 2026-07-28 (SEP-2577).
             * @category sampling
             */
            export interface CreateMessageRequestParams {
              /**
               * The maximum number of tokens.
               */
              maxTokens: number;
              /**
               * @deprecated The `"thisServer"` and `"allServers"` values are deprecated
               * as of protocol version 2025-11-25.
               */
              includeContext?: "none" | "thisServer" | "allServers";
            }

            /** @deprecated Gone. */
            export type LoggingLevel = "debug" | "info";

            export interface Active {
              name: string;
            }
            TS;

        $this->assertSame([
            'CreateMessageRequestParams' => 'Deprecated as of protocol version 2026-07-28 (SEP-2577).',
            'CreateMessageRequestParams.includeContext' => 'The `"thisServer"` and `"allServers"` values are deprecated as of protocol version 2025-11-25.',
            'LoggingLevel' => 'Gone.',
        ], (new DeprecationReader())->read($typeScript));
    }

    public function testReadsTheVendoredSchema(): void
    {
        $deprecations = (new DeprecationReader())->read((string) file_get_contents(__DIR__.'/../../../../tools/schema-generator/spec/2026-07-28/schema.ts'));

        $this->assertStringStartsWith('Deprecated as of protocol version 2026-07-28 (SEP-2577).', $deprecations['SamplingMessage'] ?? '');
        $this->assertArrayHasKey('CreateMessageRequestParams.includeContext', $deprecations);
    }
}
