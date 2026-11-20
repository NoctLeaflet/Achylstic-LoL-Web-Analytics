<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database backups (php artisan db:backup)
    |--------------------------------------------------------------------------
    |
    | directory: where the compressed dumps go (kept out of git and out of public/).
    | keep:      how many of the newest dumps to keep; older ones are deleted.
    | mysqldump: the mysqldump program. Just "mysqldump" when it is on PATH; on
    |            Windows/Laragon set MYSQLDUMP_PATH to the full path.
    |
    */

    'directory' => env('BACKUP_DIRECTORY', storage_path('app/backups')),

    'keep' => (int) env('BACKUP_KEEP', 14),

    'mysqldump' => env('MYSQLDUMP_PATH', 'mysqldump'),

];
