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

use Mcp\Schema\JsonRpc\Error;

/**
 * Which protocol era one inbound HTTP request belongs to.
 *
 * The outcome of {@see InboundClassifier}: an era to route to, or a rejection
 * the entry answers with directly. Never both.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class EraClassification
{
    private function __construct(
        public readonly bool $modern,
        public readonly ?string $claimedVersion,
        public readonly ?Error $error,
        public readonly int $httpStatus,
    ) {
    }

    /**
     * The handshake era: everything that makes no per-request envelope claim.
     */
    public static function legacy(?string $claimedVersion = null): self
    {
        return new self(false, $claimedVersion, null, 200);
    }

    /**
     * The modern era, claiming $version — which may be one this server does not
     * serve. Routing and support are separate questions, and the dispatcher
     * owns the second one so its answer can name what it does support.
     */
    public static function modern(string $version): self
    {
        return new self(true, $version, null, 200);
    }

    public static function reject(Error $error, int $httpStatus): self
    {
        return new self(false, null, $error, $httpStatus);
    }

    public function isRejected(): bool
    {
        return null !== $this->error;
    }
}
