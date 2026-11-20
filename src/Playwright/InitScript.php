<?php

declare(strict_types=1);

namespace Pest\Browser\Playwright;

/**
 * @internal
 */
final class InitScript
{
    /**
     * Get the JavaScript code for the initialization script.
     */
    public static function get(): string
    {
        $axe = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/axe.min.js'
        );

        return <<<JS
            $axe

            window.__pestBrowser = {
                jsErrors: [],
                consoleLogs: [],
                openRequests: 0,
                lastNetworkActivity: performance.now()
            };

            // Resource Timing only lists a request once it ends, so waitForLoadState('networkidle')
            // counts open fetch/XHR requests itself.
            const requestStarted = () => {
                window.__pestBrowser.openRequests++;
                window.__pestBrowser.lastNetworkActivity = performance.now();
            };
            const requestEnded = () => {
                window.__pestBrowser.openRequests--;
                window.__pestBrowser.lastNetworkActivity = performance.now();
            };

            const originalFetch = window.fetch;
            window.fetch = function(...args) {
                requestStarted();
                return originalFetch.apply(this, args).finally(requestEnded);
            };

            const originalSend = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.send = function(...args) {
                requestStarted();
                this.addEventListener('loadend', requestEnded, {once: true});
                return originalSend.apply(this, args);
            };

            const originalConsoleLog = console.log;
            console.log = function(...args) {
                window.__pestBrowser.consoleLogs.push({
                    timestamp: new Date().getTime(),
                    message: args.map(arg => String(arg)).join(' ')
                });
                originalConsoleLog.apply(console, args);
            };

            window.addEventListener('error', (e) => {
                window.__pestBrowser.jsErrors.push({
                    message: e.message,
                    filename: e.filename,
                    lineno: e.lineno,
                    colno: e.colno
                });
            });

            window.alert = function(msg){
              document.body.innerHTML = '<h1 style="font-weight:bold">alert() called but Playwright dismissed it</h1><h2>' + msg + '</h2>';
            };
            JS;
    }
}
