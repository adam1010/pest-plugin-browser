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
                consoleLogs: []
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
