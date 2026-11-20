<?php

declare(strict_types=1);

namespace Pest\Browser\Playwright;

use Exception;

/**
 * @internal
 */
final class Context
{
    use Concerns\InteractsWithPlaywright;

    /**
     * Indicates whether the browser context is closed.
     */
    private bool $closed = false;

    /**
     * @var array<int, Page>
     */
    public array $openPages = [];

    /**
     * Creates a new context instance.
     */
    public function __construct(
        private readonly Browser $browser,
        private readonly string $guid,
        private readonly string|null $tracingGuid = null,
    ) {
        //
    }

    /**
     * Gets the browser instance.
     */
    public function browser(): Browser
    {
        return $this->browser;
    }

    /**
     * Creates a new page in the context.
     */
    public function newPage(): Page
    {
        $response = Client::instance()->execute($this->guid, 'newPage');

        $frameGuid = '';
        $pageGuid = '';
        $videoRecordingGuid = null;
        $traceGuid = null;

        /** @var array{method: string|null, params: array{type: string|null, guid: string, initializer: array{url: string}}, result: array{page: array{guid: string|null}}} $message */
        foreach ($response as $message) {
            if (isset($message['method']) && $message['method'] === '__create__' && (isset($message['params']['type']) && $message['params']['type'] === 'Frame')) {
                $frameGuid = $message['params']['guid'];
            }

            if (isset($message['result']['page']['guid'])) {
                $pageGuid = $message['result']['page']['guid'];
            }

            if(($message['params']['type'] ?? null) === 'Artifact' && isset($message['params']['initializer']['absolutePath'])) {
                $videoRecordingGuid = $message['params']['guid'];
            }
        }

        $messageCount = 0;
        while($videoRecordingGuid === null && $messageCount++ < 3){
            // Only events are read here, so there is no reply of ours for an error to belong to.
            $message = Client::instance()->getMessageOffWebsocket(requestId: '');
            if(($message['params']['type'] ?? null) === 'Artifact' && isset($message['params']['initializer']['absolutePath'])) {
                $videoRecordingGuid = $message['params']['guid'];
            }
        }

        // Enable Tracing  https://trace.playwright.dev/
      Client::instance()->sendWebsocketMessage($this->tracingGuid, 'tracingStart', ['screenshots' => true,'snapshots' => true]);
      Client::instance()->sendWebsocketMessage($this->tracingGuid, 'tracingStartChunk');

        $page = new Page($this, $pageGuid, $frameGuid, $videoRecordingGuid);
        $this->openPages[] = $page;

        return $page;
    }

    public function saveTraceFile(): void{
      $requestId = Client::instance()->sendWebsocketMessage($this->tracingGuid, "tracingStopChunk", ["mode"=>"archive"]);

      $artifactGuid = null;
      while($artifactGuid === null){
        $message = Client::instance()->getMessageOffWebsocket(requestId: $requestId);

        if($this->tracingGuid === ($message['guid'] ?? null) && ($message['params']['type'] ?? null) === 'Artifact') {
            $artifactGuid = $message['params']['guid'];
        }
      }

      $response = Client::instance()->execute($artifactGuid, "saveAsStream");

      $streamGuid = null;
      foreach($response as $message){
        if(($message['params']['type'] ?? null) == 'Stream'){
          $streamGuid = $message['params']['guid'];
        }
      }

      $bytes = Page::downloadBinaryStream($streamGuid);

      // @phpstan-ignore-next-line
      $filename = str_replace('__pest_evaluable_', '', test()->name());
      $filename = str_starts_with($filename, 'it_') ? substr($filename, 3) : $filename;
      if(test()->status()->isFailure() || test()->status()->isError()){
          $filename = 'FAILED_' . $filename;
      }

      file_put_contents(base_path("tests/Playwright/videos/" . $filename . '.trace.zip'), $bytes);
    }

    /**
     * Closes the browser context.
     */
    public function close(): void
    {
        if ($this->browser->isClosed() || $this->closed) {
            return;
        }

        // Only a failure's trace and video are worth the time it takes to download them
        $failed = test()->status()->isFailure() || test()->status()->isError();

        if ($failed) {
            sleep(1); // Test is over, give the video one more second of length
            $this->saveTraceFile();
        } else {
            $this->discardTrace();
        }

        try {
            // fix this...
            $response = $this->sendMessage('close');
            $this->processVoidResponse($response);
        } catch (Exception $e) {
            if (str_contains($e->getMessage(), 'has been closed')) {
                return;
            }

            throw $e;
        }

        $this->closed = true;

        if (! $failed) {
            return;
        }

        foreach($this->openPages as $page) {
            $page->saveVideoRecording();
        }
    }

    /**
     * Stops the trace chunk without archiving it.
     */
    private function discardTrace(): void
    {
        $requestId = Client::instance()->sendWebsocketMessage($this->tracingGuid, 'tracingStopChunk', ['mode' => 'discard']);

        do {
            $message = Client::instance()->getMessageOffWebsocket(requestId: $requestId);
        } while (($message['id'] ?? null) !== $requestId);
    }

    /**
     * Checks if the browser context is closed.
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Adds a script which will be evaluated.
     */
    public function addInitScript(string $script): self
    {
        $response = $this->sendMessage('addInitScript', ['source' => $script]);
        $this->processVoidResponse($response);

        return $this;
    }
}
