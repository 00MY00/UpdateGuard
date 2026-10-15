<?php

namespace Modules\UpdateGuard\Services\Security;

/**
 * Rules of the local security scanner: check family, severity, weight in the risk score, message. Pure PHP.
 *
 * A rule counts ONCE in the score whatever the number of occurrences (every occurrence is still listed in the
 * report), and each check family has a ceiling: a big module with many ordinary base64_decode() calls does not add up
 * to a high score. Only combinations (decoded payload executed, remote code executed, writes into system folders...)
 * reach HIGH or CRITICAL on their own.
 */
class Rules
{
    const LOW = 'LOW';
    const MEDIUM = 'MEDIUM';
    const HIGH = 'HIGH';
    const CRITICAL = 'CRITICAL';

    /** rule => [check, severity, weight, message] */
    const RULES = [
        // integrity / archive
        'checksum_mismatch'     => ['integrity', self::CRITICAL, 100, 'SHA-256 of the package does not match SHA256SUMS'],
        'archive_traversal'     => ['structure', self::CRITICAL, 100, 'path traversal or absolute path in the archive (Zip Slip)'],
        'archive_symlink'       => ['structure', self::CRITICAL, 100, 'symbolic link in the archive'],
        'archive_duplicate'     => ['structure', self::HIGH, 60, 'duplicate or conflicting paths in the archive'],
        'archive_bomb'          => ['structure', self::HIGH, 60, 'archive much bigger once extracted (zip bomb?)'],
        'archive_invalid'       => ['structure', self::HIGH, 60, 'corrupt or inconsistent archive'],

        // PHP static analysis
        'eval_decode'           => ['php', self::HIGH, 60, 'eval() with dynamically decoded content'],
        'eval_dynamic'          => ['php', self::MEDIUM, 25, 'eval() of non-constant code'],
        'eval_static'           => ['php', self::LOW, 10, 'eval() detected'],
        'assert_code'           => ['php', self::MEDIUM, 20, 'assert() with code as argument'],
        'command_exec'          => ['php', self::MEDIUM, 20, 'command execution function detected'],
        'backtick'              => ['php', self::MEDIUM, 20, 'shell execution with backticks'],
        'create_function'       => ['php', self::MEDIUM, 20, 'create_function() detected'],
        'preg_replace_e'        => ['php', self::HIGH, 40, 'preg_replace() with the /e modifier (code execution)'],
        'constructed_name'      => ['php', self::HIGH, 40, 'name of a dangerous function built from string fragments'],
        'dynamic_call_decoded'  => ['php', self::MEDIUM, 25, 'dynamic function call in a file that decodes data'],
        'base64_decode'         => ['php', self::LOW, 5, 'base64_decode() detected'],
        'decode_function'       => ['php', self::LOW, 5, 'decoding / decompression function detected'],

        // obfuscation
        'layered_decoding'      => ['obfuscation', self::HIGH, 30, 'several layers of decoding'],
        'long_encoded_string'   => ['obfuscation', self::MEDIUM, 30, 'very long encoded string in PHP code'],
        'hex_encoded_code'      => ['obfuscation', self::MEDIUM, 30, 'hexadecimal / octal encoded code'],
        'high_entropy'          => ['obfuscation', self::MEDIUM, 15, 'string with unusually high entropy'],
        'php_in_media'          => ['obfuscation', self::HIGH, 50, 'PHP code hidden in an image or binary file'],
        'php_in_text'           => ['obfuscation', self::MEDIUM, 20, 'PHP code in a non-PHP file'],
        'halt_compiler_payload' => ['obfuscation', self::MEDIUM, 20, 'data appended after __halt_compiler()'],

        // executables
        'binary'                => ['executables', self::HIGH, 50, 'executable binary in the package'],
        'phar'                  => ['executables', self::MEDIUM, 30, 'PHP archive (.phar) in the package'],

        // filesystem behaviour
        'system_path_write'     => ['filesystem', self::HIGH, 70, 'write into a system directory'],
        'core_write'            => ['filesystem', self::HIGH, 60, 'write into FreeScout core files'],
        'env_write'             => ['filesystem', self::HIGH, 60, 'access to the .env file for writing'],
        'webserver_config'      => ['filesystem', self::HIGH, 60, 'change of web server / PHP configuration'],
        'other_module_write'    => ['filesystem', self::HIGH, 40, 'write into another module'],
        'relative_escape'       => ['filesystem', self::MEDIUM, 30, 'write to a path going up with "../"'],
        'destructive_command'   => ['filesystem', self::HIGH, 50, 'destructive command (rm -rf...)'],

        // network behaviour
        'network'               => ['network', self::LOW, 5, 'external network request'],
        'unknown_domain'        => ['network', self::LOW, 5, 'request to a domain outside the usual sources'],
        'exfiltration'          => ['network', self::HIGH, 50, 'network request in a file that reads secrets (.env, APP_KEY, DB password)'],
        'remote_code_exec'      => ['network', self::CRITICAL, 80, 'remote code downloaded and executed'],

        // installer / update scripts
        'installer_pipe_shell'  => ['installer', self::HIGH, 50, 'script downloads and runs code (curl | sh)'],
        'installer_system'      => ['installer', self::HIGH, 40, 'script changes the system (cron, /etc, users, permissions)'],
        'installer_exec'        => ['installer', self::HIGH, 30, 'migration / installer runs commands or network requests'],
        'composer_scripts'      => ['installer', self::MEDIUM, 20, 'composer.json defines scripts'],

        // external providers (one rule per level)
        'external_warning'      => ['external', self::MEDIUM, 15, 'external scanner: suspicious detections'],
        'external_high'         => ['external', self::HIGH, 50, 'external scanner: several malicious detections'],
        'external_critical'     => ['external', self::CRITICAL, 80, 'external scanner: many malicious detections'],
    ];

    /** Ceiling of each check family in the score. */
    const CAPS = [
        'integrity'   => 100,
        'structure'   => 100,
        'php'         => 70,
        'obfuscation' => 50,
        'executables' => 60,
        'filesystem'  => 100,
        'network'     => 100,
        'installer'   => 80,
        'external'    => 100,
    ];

    /** Local checks, in display order (each one can be switched off). */
    const CHECKS = ['integrity', 'structure', 'php', 'obfuscation', 'executables', 'filesystem', 'network', 'installer'];

    /** Severity order. */
    const ORDER = ['' => 0, self::LOW => 1, self::MEDIUM => 2, self::HIGH => 3, self::CRITICAL => 4];

    public static function finding($rule, $file = '', $line = 0, $detail = '')
    {
        $r = self::RULES[$rule];

        return [
            'rule'     => $rule,
            'check'    => $r[0],
            'severity' => $r[1],
            'weight'   => $r[2],
            'file'     => (string) $file,
            'line'     => (int) $line,
            'message'  => $r[3].($detail !== '' ? ': '.$detail : ''),
        ];
    }
}
