<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Stateless;

use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ElicitationMode;
use Mcp\Schema\Enum\SamplingContext;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Request\ListRootsRequest;
use Mcp\Schema\Result\InputRequiredResult;

/**
 * What a multi round-trip ask requires of the client, and whether it said it
 * could do it.
 *
 * A server MUST NOT put a request into `inputRequests` that the client has not
 * declared support for: the client would have nothing to answer with and the
 * retry could never carry it. Since capabilities travel per request in this
 * revision, the check is possible right where the answer is built — which is
 * better than leaving it to every handler, and better than discovering it when
 * the retry never comes.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class InputRequestCapabilities
{
    /**
     * The capabilities $result needs that $declared does not offer, or null
     * when the client can answer everything it is being asked.
     */
    public static function missing(InputRequiredResult $result, ClientCapabilities $declared): ?ClientCapabilities
    {
        $roots = false;
        $sampling = false;
        $samplingTools = false;
        $samplingContext = false;
        $elicitationForm = false;
        $elicitationUrl = false;

        foreach ($result->inputRequests as $request) {
            if ($request instanceof ListRootsRequest) {
                $roots = $roots || true !== $declared->roots;

                continue;
            }

            if ($request instanceof CreateSamplingMessageRequest) {
                $sampling = $sampling || true !== $declared->sampling;

                if (null !== $request->tools || null !== $request->toolChoice) {
                    $samplingTools = $samplingTools || true !== $declared->samplingTools;
                }

                // `none` is the default and needs nothing; the other two are
                // deprecated values that only a declaring client understands.
                if (null !== $request->includeContext && SamplingContext::NONE !== $request->includeContext) {
                    $samplingContext = $samplingContext || true !== $declared->samplingContext;
                }

                continue;
            }

            if ($request instanceof ElicitRequest) {
                if (ElicitationMode::Url === $request->mode) {
                    $elicitationUrl = $elicitationUrl || true !== $declared->elicitationUrl;
                } else {
                    $elicitationForm = $elicitationForm || true !== $declared->elicitationForm;
                }
            }
        }

        if (!$roots && !$sampling && !$samplingTools && !$samplingContext && !$elicitationForm && !$elicitationUrl) {
            return null;
        }

        return new ClientCapabilities(
            roots: $roots,
            sampling: $sampling ?: null,
            elicitation: ($elicitationForm || $elicitationUrl) ?: null,
            samplingContext: $samplingContext ?: null,
            samplingTools: $samplingTools ?: null,
            elicitationForm: $elicitationForm ?: null,
            elicitationUrl: $elicitationUrl ?: null,
        );
    }
}
