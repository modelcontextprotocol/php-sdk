<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Enum;

/**
 * Where a task is in its life (SEP-2663).
 *
 * The distinction that matters most is between {@see self::Completed} and
 * {@see self::Failed}: a tool that ran and reported a problem *completed* — its
 * result carries `isError` — while `failed` is reserved for a protocol-level
 * error, where there is no tool result at all. Reading a tool error as `failed`
 * would hide it from the model, which is the thing `isError` exists to prevent.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
enum TaskStatus: string
{
    /** The work is in progress. */
    case Working = 'working';

    /** The server needs client input before it can continue. */
    case InputRequired = 'input_required';

    /** The work finished; the result is what the original request would have returned. */
    case Completed = 'completed';

    /** A protocol-level error stopped the work; the error is inlined. */
    case Failed = 'failed';

    /** The work was cancelled. Cooperative: reaching it is never guaranteed. */
    case Cancelled = 'cancelled';

    /**
     * Whether the task can still change. Terminal states never do.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            self::Working, self::InputRequired => false,
        };
    }
}
