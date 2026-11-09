<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Health check alerts (php artisan app:health-check, every minute)
    |--------------------------------------------------------------------------
    |
    | Runs in the scheduler container. Emails go out through the normal mail
    | settings (MAIL_*), so those must point at a real SMTP server.
    |
    */

    // Comma-separated. Empty = checks still run and log, but nobody is emailed.
    'alert_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('HEALTH_ALERT_EMAILS', ''))))),

    // Consecutive failed checks before a service counts as down; 2 rides out a container restart.
    'fail_threshold' => (int) env('HEALTH_FAIL_THRESHOLD', 2),

    // While something stays down, remind every N minutes.
    'remind_minutes' => (int) env('HEALTH_REMIND_MINUTES', 60),

    // Alert when the disk holding storage/ has less free space than this.
    'min_free_disk_percent' => (int) env('HEALTH_MIN_FREE_DISK_PERCENT', 10),

    // The scheduler uses host networking, so 127.0.0.1 is the server itself.
    'backend_url' => env('HEALTH_BACKEND_URL', 'http://127.0.0.1:8000/up'),
    'openwebui_url' => env('HEALTH_OPENWEBUI_URL', 'http://127.0.0.1:3000/health'),

];
