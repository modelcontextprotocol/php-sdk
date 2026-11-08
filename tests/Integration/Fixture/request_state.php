<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Server for the `requestState` tests, over `php -S` or stdio. MCP_REQUEST_STATE_KEYS holds the JSON-encoded key or
 * key ring, MCP_REQUEST_STATE_BINDER turns on the binder, MCP_REQUEST_STATE_ANONYMOUS drops the authorization,
 * MCP_REQUEST_STATE_TTL sets the TTL, MCP_REQUEST_STATE_SUBJECT_CLAIM names the claim identifying a user and
 * MCP_REQUEST_STATE_NO_SHIM turns off the input-required shim, MCP_REQUEST_STATE_SESSIONS keeps sessions in a directory.
 */

use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Stateless\OriginatingRequestBinder;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\Middleware\PassthroughMiddleware;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Server\Transport\StreamableHttpTransport;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

$subject = static fn (RequestContext $context): string => $context->getAccessToken()?->getSubject() ?? 'nobody';

// Two asks, so the second one has to carry the first answer in the state the SDK mints.
$approver = static function (RequestContext $context): string {
    $gateway = $context->getClientGateway();
    $schema = new ElicitationSchema(['v' => new StringSchemaDefinition('Value')], ['v']);

    $approver = $gateway->elicit('Who approves this?', $schema, key: 'approver')->content['v'] ?? '?';
    $gateway->elicit('One-time code?', $schema, key: 'code');

    return (string) $approver;
};

$builder = Server::builder()
    ->setServerInfo('request-state-server', '1.0.0')
    ->addTool(
        static fn (RequestContext $context, string $to, int $amount): string => sprintf('%s moved %d to %s, approved by %s', $subject($context), $amount, $to, $approver($context)),
        name: 'transfer',
        description: 'Moves money after two asks.',
    )
    ->addTool(
        static fn (RequestContext $context, string $account): string => sprintf('%s closed %s, approved by %s', $subject($context), $account, $approver($context)),
        name: 'close_account',
        description: 'Closes an account after the same two asks.',
    )
    ->addTool(
        // Authorizes once and trusts its own signed state on the retry, the reason the state needs integrity at all.
        static function (RequestContext $context, int $order) use ($subject): CallToolResult|InputRequiredResult {
            if (true === ($context->getInputContext()?->requestState()['authorized'] ?? null)) {
                return new CallToolResult([new TextContent(sprintf('%s refunded order %d', $subject($context), $order))]);
            }

            if (!in_array($subject($context), ['alice', 'nobody'], true)) {
                return new CallToolResult([new TextContent('Only a manager may refund.')], isError: true);
            }

            return new InputRequiredResult(
                ['reason' => new ElicitRequest('Why?', new ElicitationSchema(['v' => new StringSchemaDefinition('Value')], ['v']))],
                requestState: $context->mintRequestState(['authorized' => true]),
            );
        },
        name: 'refund',
        description: 'Refunds an order, for managers only.',
    )
    ->addTool(
        // Takes any JSON, so the handler sees the arguments the way the client shaped them.
        static fn (RequestContext $context, mixed $options): string => sprintf('%s, approved by %s', json_encode($options, depth: 1024), $approver($context)),
        name: 'inspect',
        description: 'Echoes what it was given after two asks.',
    )
    ->addTool(
        static function (RequestContext $context, int $amount) use ($approver): string {
            $context->getClientGateway()->progress(0.5, 1.0);

            return sprintf('reported %d, approved by %s', $amount, $approver($context));
        },
        name: 'report',
        description: 'Reports progress before the two asks, so its rounds answer as a stream.',
    )
    ->addPrompt(
        static fn (RequestContext $context, string $topic): array => [new PromptMessage(Role::User, new TextContent(sprintf('brief on %s, approved by %s', $topic, $approver($context))))],
        name: 'brief',
        description: 'A brief after two asks.',
    )
    ->addPrompt(
        static fn (RequestContext $context, string $topic): array => [new PromptMessage(Role::User, new TextContent(sprintf('leak of %s, approved by %s', $topic, $approver($context))))],
        name: 'leak',
        description: 'Another prompt after the same two asks.',
    )
    ->addResourceTemplate(
        static fn (string $id, RequestContext $context): string => sprintf('contents of %s, approved by %s', $id, $approver($context)),
        'doc://{id}',
        name: 'doc',
        mimeType: 'text/plain',
    )
    ->addTool(static fn (): string => 'pong', name: 'ping', description: 'Asks nothing.');

$keys = json_decode((string) getenv('MCP_REQUEST_STATE_KEYS'), true, flags: \JSON_THROW_ON_ERROR);

$binder = null;

if ('' !== (string) getenv('MCP_REQUEST_STATE_BINDER')) {
    $claim = (string) getenv('MCP_REQUEST_STATE_SUBJECT_CLAIM');

    $binder = new OriginatingRequestBinder('' === $claim ? null : static function (AccessToken $token) use ($claim): ?string {
        $value = $token->getClaim($claim);

        return is_string($value) ? $value : null;
    });
}

$builder->setRequestState($keys, (int) (getenv('MCP_REQUEST_STATE_TTL') ?: 600), $binder);

if ('' !== (string) getenv('MCP_REQUEST_STATE_SESSIONS')) {
    $builder->setSession(new FileSessionStore((string) getenv('MCP_REQUEST_STATE_SESSIONS')));
}

if ('' !== (string) getenv('MCP_REQUEST_STATE_NO_SHIM')) {
    $builder->withoutInputRequiredShim();
}

if ('cli-server' !== \PHP_SAPI) {
    $builder->build()->run(new StdioTransport());

    return;
}

$middleware = [new PassthroughMiddleware()];

if ('' === (string) getenv('MCP_REQUEST_STATE_ANONYMOUS')) {
    $middleware = [new AuthorizationMiddleware(
        new class implements AuthorizationTokenValidatorInterface {
            private const ISSUER = 'https://auth.example.com';

            public function validate(string $accessToken): AuthorizationResult
            {
                $claims = match ($accessToken) {
                    'alice' => ['sub' => 'alice', 'client_id' => 'app', 'jti' => 'first'],
                    'alice-refreshed' => ['sub' => 'alice', 'client_id' => 'app', 'jti' => 'second'],
                    'alice-other-client' => ['sub' => 'alice', 'client_id' => 'other-app', 'jti' => 'third'],
                    'alice-other-issuer' => ['sub' => 'alice', 'client_id' => 'app', 'iss' => 'https://other.example.com', 'jti' => 'fifth'],
                    'mallory' => ['sub' => 'mallory', 'client_id' => 'app', 'jti' => 'fourth'],
                    'no-subject-alice' => ['client_id' => 'app', 'email' => 'alice@example.com'],
                    'no-subject-alice-refreshed' => ['client_id' => 'app', 'email' => 'alice@example.com', 'jti' => 'sixth'],
                    'no-subject-alice-other-client' => ['client_id' => 'other-app', 'email' => 'alice@example.com'],
                    'no-subject-mallory' => ['client_id' => 'app', 'email' => 'mallory@example.com'],
                    default => null,
                };

                if (null === $claims) {
                    return AuthorizationResult::unauthorized();
                }

                return AuthorizationResult::allow(new AccessToken(['mcp'], ['iss' => self::ISSUER, ...$claims]));
            }
        },
        new ProtectedResourceMetadata('http://127.0.0.1/', ['http://127.0.0.1/']),
    )];
}

$request = (new Psr17Factory())->createServerRequestFromGlobals();

(new SapiEmitter())->emit($builder->build()->run(new StreamableHttpTransport($request, middleware: $middleware)));
