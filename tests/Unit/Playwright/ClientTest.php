<?php

declare(strict_types=1);

use Amp\CancelledException;
use Amp\Websocket\Client\WebsocketConnection;
use Pest\Browser\Playwright\Client;

function clientWith(WebsocketConnection $connection): Client
{
    $client = (new ReflectionClass(Client::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Client::class, 'websocketConnection'))->setValue($client, $connection);

    return $client;
}

test('fails instead of spinning when the server closes the websocket', function (): void {
    $connection = $this->createStub(WebsocketConnection::class);
    $connection->method('receive')->willReturn(null);

    expect(fn () => clientWith($connection)->getMessageOffWebsocket())
        ->toThrow(RuntimeException::class, 'closed the websocket connection');
});

test('fails and closes the connection when the server stays silent past the receive timeout', function (): void {
    $connection = $this->createMock(WebsocketConnection::class);
    $connection->method('receive')->willThrowException(new CancelledException());
    $connection->expects($this->once())->method('close');

    expect(fn () => clientWith($connection)->getMessageOffWebsocket())
        ->toThrow(RuntimeException::class, 'No message from the Playwright server');
});
