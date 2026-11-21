<?php

return [
    // Errors stay on this server. Nothing is sent anywhere; `php artisan error-report:show` prints one.
    'enabled' => env('ERROR_REPORT', true),
    'path' => storage_path('error-reports'),
    'max_entries' => 200,
    // Where the anonymised report goes, at counts 1, 2, 4, 8... of each error so a repeat
    // does not flood anyone. Both empty keeps everything on the server.
    // Log channels from config/logging.php, e.g. ['slack'].
    'channels' => array_filter(array_map('trim', explode(',', (string) env('ERROR_REPORT_CHANNELS', '')))),
    // Addresses, sent with the app's default mailer, only when it delivers (not `log` or `array`).
    'mail' => array_filter(array_map('trim', explode(',', (string) env('ERROR_REPORT_MAIL', '')))),
    // Deployed commit, so the agent can check out the same code. Falls back to .git/HEAD.
    'release' => env('APP_RELEASE'),
];
