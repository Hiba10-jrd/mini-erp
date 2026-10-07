<?php

return [
    'enabled' => env('BACKUP_ENABLED', true),
    'disk' => env('BACKUP_DISK', 'backups'),
    'path' => env('BACKUP_PATH', 'mini-erp'),
    'schedule_time' => env('BACKUP_SCHEDULE_TIME', '02:00'),
    // Unique accepted format: base64: followed by canonical Base64 of 32 random bytes.
    // This is an independent key, never APP_KEY. One configured key_id per instance.
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),
    'key_id' => env('BACKUP_KEY_ID', 'v1'),
    'restore' => [
        // An existing empty schema and a dedicated account restricted to that schema.
        // No fallback to the application's DB credentials; never create/drop databases.
        'mysql' => [
            'driver' => env('BACKUP_RESTORE_DB_DRIVER', 'mysql'),
            'host' => env('BACKUP_RESTORE_DB_HOST', '127.0.0.1'),
            'port' => env('BACKUP_RESTORE_DB_PORT', 3306),
            'username' => env('BACKUP_RESTORE_DB_USERNAME'),
            'password' => env('BACKUP_RESTORE_DB_PASSWORD'),
        ],
    ],
    'retention' => [
        'daily' => env('BACKUP_RETENTION_DAILY', 7),
        'weekly' => env('BACKUP_RETENTION_WEEKLY', 4),
        'monthly' => env('BACKUP_RETENTION_MONTHLY', 3),
    ],
];
