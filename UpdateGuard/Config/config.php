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

        // Security Gate (before installation) — see Services/Security
        'security_gate'                  => true,
        'sec_check_integrity'            => true,   // SHA-256 against SHA256SUMS
        'sec_check_structure'            => true,   // Zip Slip, links, duplicates, zip bomb
        'sec_check_php'                  => true,   // dangerous PHP functions and combinations
        'sec_check_obfuscation'          => true,
        'sec_check_executables'          => true,
        'sec_check_filesystem'           => true,   // writes outside the module directory
        'sec_check_network'              => true,
        'sec_check_installer'            => true,   // installer / update / migration scripts
        'sec_block_checksum'             => true,
        'sec_block_traversal'            => true,
        'sec_block_critical_local'       => true,
        'sec_block_score'                => true,
        'sec_score_threshold'            => 80,
        'sec_block_external'             => true,   // an external scanner reports a blocking result
        'sec_block_external_unavailable' => false,
        'sec_allow_override'             => true,
        'sec_save_reports'               => true,
        'sec_confirm'                    => 'warnings', // never | warnings | always (manual updates)
        'sec_level_medium'               => 21,
        'sec_level_high'                 => 50,
        'sec_level_critical'             => 80,
        'sec_cache_days'                 => 7,
        'sec_report_retention_days'      => 90,
        'sec_report_max'                 => 500,

        // VirusTotal (optional, off by default; the API key is stored encrypted, see Services/Security/Secrets)
        'vt_enabled'                     => false,
        'vt_hash_first'                  => true,
        'vt_auto_upload'                 => false,
        'vt_ask_upload'                  => true,
        'vt_block_threshold'             => 3,
        'vt_warning_min'                 => 1,
        'vt_high_min'                    => 3,
        'vt_critical_min'                => 6,
        'vt_on_failure'                  => 'warn', // warn | block
        'vt_timeout'                     => 30,
        'vt_plan'                        => 'public', // public (free: limited) | premium (no local limit)
        'vt_rate_minute'                 => 4,        // free API limits (VirusTotal, 2026): 4 / min, 500 / day,
        'vt_quota_day'                   => 500,      // 15.5 K / month; adjustable if the account differs
        'vt_quota_month'                 => 15500,

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
