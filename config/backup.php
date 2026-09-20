<?php

/*
|--------------------------------------------------------------------------
| Database backups
|--------------------------------------------------------------------------
|
| `artisan db:backup` writes a compressed dump and a manifest to a disk and
| removes old ones; `artisan db:restore-drill` restores the newest dump into a
| scratch database and checks it, because a backup nobody has restored is a
| hope, not a backup. The scheduler runs both when BACKUP_ENABLED is true.
|
| On a managed database (Azure Flexible Server) the service's own
| point-in-time restore is the first line; this is the copy that survives
| losing the server or the account region. Put it on a disk that is not the
| app's own volume (BACKUP_DISK=s3).
|
*/

return [

    'enabled' => (bool) env('BACKUP_ENABLED', false),

    'disk' => env('BACKUP_DISK', 'local'),

    'path' => 'backups/database',

    // Dumps older than this are removed, but the newest keep_minimum always stay.
    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),
    'keep_minimum' => 3,

    // Daily, server time (UTC). The drill runs weekly, an hour later.
    'at' => '02:15',
    'drill_at' => '03:15',

    // The newest dump may be this old before the health check reports it.
    'max_age_hours' => 26,

    // The drill restores here, then drops it. The name must end in
    // "_restore_drill"; the database user needs CREATE and DROP on it.
    'drill_database' => env('BACKUP_DRILL_DATABASE'),

    // Counted before the dump and again after the restore.
    'verify_tables' => ['users', 'admins', 'documents', 'pdf_state'],

    'binaries' => [
        'mysqldump' => env('MYSQLDUMP_BINARY', 'mysqldump'),
        'mysql' => env('MYSQL_BINARY', 'mysql'),
    ],

    'timeout_seconds' => 3600,
];
