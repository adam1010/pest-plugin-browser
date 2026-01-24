<?php

declare(strict_types=1);

namespace Pest\Browser\Playwright;

use Amp\Websocket\Client\WebsocketConnection;
use Generator;
use Pest\Browser\Exceptions\BrowserExpectationFailedException;
use Pest\Browser\Exceptions\PlaywrightOutdatedException;
use PHPUnit\Framework\ExpectationFailedException;

use function Amp\Websocket\Client\connect;

/**
 * @internal
 */
final class Client
{
    /**
     * Client instance.
     */
    private static ?Client $instance = null;

    /**
     * WebSocket client instance.
     */
    private ?WebsocketConnection $websocketConnection = null;

    /**
     * Default timeout for requests in milliseconds.
     */
    private int $timeout = 5_000;

    /**
     * Returns the current client instance.
     */
    public static function instance(): self
    {
        if (! self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Connects to the Playwright server.
     */
    public function connectTo(string $url): void
    {
        if (! $this->websocketConnection instanceof WebsocketConnection) {
            $browser = Playwright::defaultBrowserType()->toPlaywrightName();

            $launchOptions = json_encode([
                'headless' => Playwright::isHeadless(),
                'ignoreHTTPSErrors' => true,
                'bypassCSP' => true,
            ]);

            $this->websocketConnection = connect(
                "ws://$url?browser=$browser&launch-options=$launchOptions",
            );
        }
    }

    public function getMessageOffWebsocket(Page|null $page = null): array|null{
        assert($this->websocketConnection instanceof WebsocketConnection, 'WebSocket client is not connected.');

        $responseJson = $this->fetch($this->websocketConnection);
        /** @var array{id: string|null, params: array{add: string|null}, error: array{error: array{message: string|null}}} $response */
        $response = json_decode($responseJson, true);

        if (isset($response['error']['error']['message'])) {
            $message = $response['error']['error']['message'];

            if (str_contains($message, 'Playwright was just installed or updated')) {
                throw new PlaywrightOutdatedException();
            }

            $ex = new ExpectationFailedException($message);
            throw ($page === null? $ex : BrowserExpectationFailedException::from($page, $ex));
        }

        return $response;
    }

    public function sendWebsocketMessage(string $guid, string $method, array $params = [], array $meta = []): string{
        assert($this->websocketConnection instanceof WebsocketConnection, 'WebSocket client is not connected.');

        $requestId = uniqid();

        $requestJson = (string) json_encode([
            'id' => $requestId,
            'guid' => $guid,
            'method' => $method,
            'params' => ['timeout' => $this->timeout, ...$params],
            'metadata' => $meta,
        ]);

        $this->websocketConnection->sendText($requestJson);

        return $requestId;
    }

    /**
     * Executes a method on the Playwright instance.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $meta
     * @return Generator<array<string, mixed>>
     */
    public function execute(string $guid, string $method, array $params = [], array $meta = [], Page|null $page = null): Generator
    {
        assert($this->websocketConnection instanceof WebsocketConnection, 'WebSocket client is not connected.');

        $requestId = $this->sendWebsocketMessage($guid, $method, $params, $meta);

        while (true) {
            $response = $this->getMessageOffWebsocket($page);

            yield $response;

            if (
                (isset($response['id']) && $response['id'] === $requestId)
                || (isset($params['waitUntil']) && isset($response['params']['add']) && $params['waitUntil'] === $response['params']['add'])
            ) {
                    break;
            }
        }
    }

    /**
     * Sets the timeout in milliseconds for requests.
     */
    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
    }

    /**
     * Returns the current timeout for requests.
     */
    public function timeout(): int
    {
        return $this->timeout;
    }

    /**
     * Fetches the response from the Playwright server.
     */
    private function fetch(WebsocketConnection $client): string
    {
        return (string) $client->receive()?->read();
    }
}
