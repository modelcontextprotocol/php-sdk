<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Registry;

/**
 * Passes raw arguments array directly to the handler.
 *
 * @author Mateu Aguilo Bosch <mateu@mateuaguilo.com>
 */
trait DynamicArgumentPreparationTrait
{
    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<int, mixed>
     */
    public function prepareArguments(array $arguments, callable $resolvedHandler): array
    {
        unset($arguments['_session']);

        return [$arguments];
    }
}
