<?php

/*
 * Default values. Every value an administrator can change is saved in FreeScout's options table
 * (Services/Settings.php, keys "updateguard.<name>") and edited on Manage › UpdateGuard › Settings.
 */
return [
    'defaults' => [
        // Updates
        'enabled'               => true,
        'check_frequency'       => 'daily',      // hourly | daily
        'check_time'            => '03:20',
        'auto_update'           => false,
        'auto_update_min_level' => 'full',       // full | protected (generic / unsupported: never automatic)

        // Security
        'require_sha256'        => true,         // a published SHA256SUMS must match, otherwise ABORT
        'fallback_validation'   => true,

        // Rollback
        'auto_rollback'         => true,
        'rollback_native'       => true,
        'rollback_generic'      => true,
        'rollback_bootstrap'    => true,
        'rollback_http500'      => true,
        'rollback_fatal'        => true,
        'rollback_timeout'      => true,
        'rollback_validation'   => true,
        'observation_minutes'   => 10,           // 0 | 5 | 10 | 30 | 60

        // Retention (0 = unlimited)
        'backups_per_module'    => 5,
        'backup_max_age_days'   => 180,
        'log_retention_days'    => 90,
        'report_retention_days' => 365,

        // Notifications
        'notify_banner'         => true,         // banner for administrators after a failure
        'notify_log'            => true,         // copy of WARNING+ lines in FreeScout's log
        'modules_page_hint'     => true,         // note on FreeScout's Manage › Modules page recommending UpdateGuard

        // Advanced
        'lock_timeout_minutes'  => 60,           // a lock older than this (or whose process is gone) is abandoned
        'process_timeout'       => 300,          // seconds per artisan sub-process (install, health check)
        'download_timeout'      => 120,
        'min_free_mb'           => 100,
    ],

    // core FreeScout routes that must still be registered after an update (generic validation)
    'core_routes' => ['login', 'dashboard', 'conversations.view', 'mailboxes.view'],

    'storage' => 'updateguard',                  // storage/updateguard/{staging,backups,locks,reports}
];
