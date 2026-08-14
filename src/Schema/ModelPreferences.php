<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema;

use Mcp\Exception\InvalidArgumentException;

/**
 * The server's preferences for model selection, requested of the client during sampling.
 *
 * Because LLMs can vary along multiple dimensions, choosing the "best" model is
 * rarely straightforward.  Different models excel in different areas—some are
 * faster but less capable, others are more capable but more expensive, and so
 * on. This interface allows servers to express their priorities across multiple
 * dimensions to help clients make an appropriate selection for their use case.
 *
 * These preferences are always advisory. The client MAY ignore them. It is also
 * up to the client to decide how to interpret these preferences and how to
 * balance them against other considerations.
 *
 * @phpstan-type ModelPreferencesData array{
 *     hints?: ModelHint[],
 *     costPriority?: float,
 *     speedPriority?: float,
 *     intelligencePriority?: float,
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ModelPreferences implements \JsonSerializable
{
    /**
     * @param ModelHint[]|null $hints Optional hints about the model to use.
     *
     * If multiple hints are specified, the client MUST evaluate them in order (such that the first match is taken).
     *
     * The client SHOULD prioritize these hints over the numeric priorities, but MAY still use the priorities to select from ambiguous matches.
     * @param float|null $costPriority         How much to prioritize cost when selecting a model. A value of 0 means cost is not important, while
     *                                         a value of 1 means cost is the most important factor. Minimum value is 0, maximum value is 1.
     * @param float|null $speedPriority        How much to prioritize sampling speed (latency) when selecting a model. A value of 0 means
     *                                         speed is not important, while a value of 1 means speed is the most important factor. Minimum value is 0, maximum value is 1.
     * @param float|null $intelligencePriority How much to prioritize intelligence and capabilities when selecting a  model. A value of 0
     *                                         means intelligence is not important, while a value of 1  means intelligence is the most important factor.
     */
    public function __construct(
        public readonly ?array $hints = null,
        public readonly ?float $costPriority = null,
        public readonly ?float $speedPriority = null,
        public readonly ?float $intelligencePriority = null,
    ) {
    }

    /**
     * @param ModelPreferencesData $preferences
     */
    public static function fromArray(array $preferences): self
    {
        if (isset($preferences['hints']) && !\is_array($preferences['hints'])) {
            throw new InvalidArgumentException('Invalid "hints" in ModelPreferences data.');
        }

        return new self(
            $preferences['hints'] ?? null,
            self::priority($preferences, 'costPriority'),
            self::priority($preferences, 'speedPriority'),
            self::priority($preferences, 'intelligencePriority'),
        );
    }

    /**
     * @param array<string, mixed> $preferences
     */
    private static function priority(array $preferences, string $key): ?float
    {
        if (!isset($preferences[$key])) {
            return null;
        }

        // JSON numbers decode to int when they have no fractional part.
        if (!\is_float($preferences[$key]) && !\is_int($preferences[$key])) {
            throw new InvalidArgumentException(\sprintf('Invalid "%s" in ModelPreferences data; expected a number.', $key));
        }

        return (float) $preferences[$key];
    }

    /**
     * @return ModelPreferencesData
     */
    public function jsonSerialize(): array
    {
        $result = [];
        if (null !== $this->hints) {
            $result['hints'] = $this->hints;
        }
        if (null !== $this->costPriority) {
            $result['costPriority'] = $this->costPriority;
        }
        if (null !== $this->speedPriority) {
            $result['speedPriority'] = $this->speedPriority;
        }
        if (null !== $this->intelligencePriority) {
            $result['intelligencePriority'] = $this->intelligencePriority;
        }

        return $result;
    }
}
