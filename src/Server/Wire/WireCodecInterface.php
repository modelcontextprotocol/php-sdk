<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Wire;

/**
 * Translates between the SDK's revision-neutral result model and the wire
 * shape one protocol era expects.
 *
 * The split this exists to protect: {@see \Mcp\Schema\Result} classes describe
 * *what* a server answered, not what any particular revision requires on the
 * wire. Fields that exist only from 2026-07-28 — the `resultType`
 * discriminator, the SEP-2549 caching hints, the `_meta` serverInfo identity —
 * are therefore not modelled on those classes at all. They are stamped here,
 * on the way out, by the codec for the negotiated version.
 *
 * Keeping them out of the model is what lets one set of result classes serve
 * every revision without a server on 2025-11-25 suddenly emitting vocabulary
 * its clients have never seen. The alternative — emitting the newer fields
 * unconditionally — is legal per the spec's forward-compatibility rule, but it
 * changes what every already-deployed client receives, which is not a decision
 * a result class should be making on its own.
 *
 * Eras, not versions: every revision through 2025-11-25 shares one wire
 * vocabulary and one codec. A new codec appears only where the vocabulary
 * actually diverges.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface WireCodecInterface
{
    /**
     * Stamps an already-serialized result with whatever this era requires.
     *
     * @param string               $method the request method the result answers
     * @param array<string, mixed> $result the neutral result body
     *
     * @return array<string, mixed>
     */
    public function encodeResult(string $method, array $result): array;
}
