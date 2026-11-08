<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Capability;

use Mcp\Server\Capability\Registry\ResourceTemplateReference;
use Mcp\Server\Exception\ResourceNotFoundException;
use Mcp\Server\Subscription\PublishingEventDispatcher;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface RegistryInterface extends ElementCatalogInterface, ElementResolverInterface, ElementWriterInterface
{
    /**
     * Whether list changes reach a notification bus, e.g. through a {@see PublishingEventDispatcher}.
     * A decorator forwards the answer of the registry it wraps.
     */
    public function publishesListChanges(): bool;

    public function hasTool(string $name): bool;

    public function hasResource(string $uri): bool;

    public function hasResourceTemplate(string $uriTemplate): bool;

    public function hasPrompt(string $name): bool;

    /**
     * @return bool true if any tools are registered
     */
    public function hasTools(): bool;

    /**
     * @return bool true if any resources are registered
     */
    public function hasResources(): bool;

    /**
     * @return bool true if any resource templates are registered
     */
    public function hasResourceTemplates(): bool;

    /**
     * @return bool true if any prompts are registered
     */
    public function hasPrompts(): bool;

    /**
     * Gets a resource template reference by URI template.
     *
     * @throws ResourceNotFoundException
     */
    public function getResourceTemplate(string $uriTemplate): ResourceTemplateReference;
}
