<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\StdioExplicitRegistration;

use Psr\Log\LoggerInterface;

final class SimpleHandlers
{
    private string $appVersion = '1.0-manual';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->logger->info('SimpleHandlers instantiated for manual registration example.');
    }

    /**
     * A manually registered tool to echo input.
     *
     * @param string $text the text to echo
     *
     * @return string the echoed text
     */
    public function echoText(string $text): string
    {
        $this->logger->info("Manual tool 'echo_text' called.", ['text' => $text]);

        return 'Echo: '.$text;
    }

    /**
     * A manually registered resource providing app version.
     *
     * @return string the application version
     */
    public function getAppVersion(): string
    {
        $this->logger->info("Manual resource 'app://version' read.");

        return $this->appVersion;
    }

    /**
     * A manually registered prompt template.
     *
     * @param string $userName the name of the user
     *
     * @return array<string, string>[] the prompt messages
     */
    public function greetingPrompt(string $userName): array
    {
        $this->logger->info("Manual prompt 'personalized_greeting' called.", ['userName' => $userName]);

        return [
            ['role' => 'user', 'content' => "Craft a personalized greeting for {$userName}."],
        ];
    }

    /**
     * A manually registered resource template.
     *
     * @param string $itemId the ID of the item
     *
     * @return array<string, string> item details
     */
    public function getItemDetails(string $itemId): array
    {
        $this->logger->info("Manual template 'item://{itemId}' resolved.", ['itemId' => $itemId]);

        return ['id' => $itemId, 'name' => "Item {$itemId}", 'description' => "Details for item {$itemId} from manual template."];
    }
}
