<?php

namespace Modules\UpdateGuard\Services\Security;

/**
 * Heuristic static analysis of the files of a module package (staging folder), before installation. Pure PHP:
 * no code of the package is ever loaded or executed (PHP files are read with token_get_all()).
 *
 * Working on tokens, not on raw text, avoids most false positives: a function name in a comment, a docblock or a
 * translation string is not a call. The presence of one function is never "malware" by itself: each finding has a
 * severity and a weight, and the RiskScore adds them with ceilings. This does not prove a module is safe.
 */
class CodeAnalyzer
{
    const COMMAND_FUNCTIONS = ['exec', 'system', 'shell_exec', 'passthru', 'proc_open', 'popen', 'pcntl_exec'];
    const DECODE_FUNCTIONS = ['base64_decode', 'gzinflate', 'gzuncompress', 'gzdecode', 'str_rot13', 'hex2bin', 'convert_uudecode'];
    const DANGEROUS_NAMES = ['system', 'exec', 'shell_exec', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'assert',
        'create_function', 'base64_decode', 'gzinflate', 'gzuncompress', 'str_rot13', 'call_user_func', 'file_put_contents'];
    const WRITE_FUNCTIONS = ['file_put_contents', 'fopen', 'unlink', 'rename', 'copy', 'chmod', 'chown', 'chgrp', 'symlink',
        'link', 'mkdir', 'rmdir', 'touch', 'move_uploaded_file', 'fputs', 'fwrite'];
    const FILE_FACADE_METHODS = ['put', 'append', 'prepend', 'delete', 'deletedirectory', 'cleandirectory', 'copy', 'move',
        'link', 'makedirectory', 'chmod', 'copydirectory', 'movedirectory', 'replace'];
    const NETWORK_FUNCTIONS = ['curl_exec', 'curl_multi_exec', 'fsockopen', 'pfsockopen', 'stream_socket_client', 'socket_connect'];
    const URL_FUNCTIONS = ['file_get_contents', 'fopen', 'readfile', 'file', 'copy', 'get_headers', 'simplexml_load_file'];
    const PATH_FUNCTIONS = ['base_path', 'app_path', 'config_path', 'public_path', 'resource_path', 'database_path', 'storage_path'];
    const TRUSTED_HOSTS = ['github.com', 'api.github.com', 'raw.githubusercontent.com', 'objects.githubusercontent.com',
        'codeload.github.com', 'gitlab.com', 'freescout.net', 'www.freescout.net', 'packagist.org', 'repo.packagist.org'];
    const PHP_EXTENSIONS = ['php', 'phtml', 'php5', 'php7', 'phps', 'inc'];
    const MEDIA_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'ico', 'bmp', 'webp', 'pdf', 'zip', 'gz', 'woff', 'woff2', 'ttf',
        'eot', 'otf', 'mp3', 'mp4', 'webm', 'ogg', 'wav'];
    const DOC_EXTENSIONS = ['md', 'markdown', 'txt', 'rst', 'stub', 'dist', 'example'];
    const SCRIPT_EXTENSIONS = ['sh', 'bash', 'ksh', 'zsh', 'py', 'pl', 'rb', 'ps1', 'bat', 'cmd'];
    const BINARY_EXTENSIONS = ['exe', 'dll', 'so', 'dylib', 'bin', 'com', 'msi', 'elf', 'scr', 'jar'];
    const MAX_CONTENT = 5242880; // files above 5 MB: only the binary checks
    const MAX_FINDINGS_PER_RULE = 25;

    /** @var array enabled checks: name => bool */
    protected $enabled;

    /** @var string */
    protected $module;

    /** @var array */
    protected $findings = [];

    /** @var array rule => count */
    protected $counts = [];

    public function __construct($moduleName, array $enabled)
    {
        $this->module = (string) $moduleName;
        $this->enabled = $enabled;
    }

    protected function on($check)
    {
        return !empty($this->enabled[$check]);
    }

    protected function add($rule, $file, $line = 0, $detail = '')
    {
        $check = Rules::RULES[$rule][0];
        if (!$this->on($check)) {
            return;
        }
        $this->counts[$rule] = ($this->counts[$rule] ?? 0) + 1;
        if ($this->counts[$rule] <= self::MAX_FINDINGS_PER_RULE) {
            $this->findings[] = Rules::finding($rule, $file, $line, $detail);
        }
    }

    /** @return array findings of every file of the folder */
    public function analyzeDirectory($dir)
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        $files = [];
        foreach ($iterator as $item) {
            if ($item->isFile() && !$item->isLink()) {
                $files[] = str_replace('\\', '/', $item->getPathname());
            }
        }
        sort($files);
        foreach ($files as $path) {
            $this->analyzeFile($path, substr($path, strlen($dir) + 1));
        }

        return $this->findings;
    }

    public function findings()
    {
        return $this->findings;
    }

    public function analyzeFile($path, $relative)
    {
        $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        $size = (int) @filesize($path);
        $head = (string) @file_get_contents($path, false, null, 0, 4096);

        // executables: magic numbers first (an extension can lie), then known extensions
        if (self::isBinaryExecutable($head) || in_array($ext, self::BINARY_EXTENSIONS, true)) {
            $this->add('binary', $relative, 0, self::describeBinary($head, $ext));

            return;
        }
        if ($ext === 'phar') {
            $this->add('phar', $relative);

            return;
        }
        if ($size > self::MAX_CONTENT) {
            return;
        }
        $content = (string) @file_get_contents($path);

        if (in_array($ext, self::PHP_EXTENSIONS, true)) {
            $this->analyzePhp($content, $relative);

            return;
        }
        // PHP hidden in other files (an image with PHP appended, a .js containing <?php...)
        if (preg_match('/<\?php|<\?=/i', $content) && !in_array($ext, self::DOC_EXTENSIONS, true)) {
            $media = in_array($ext, self::MEDIA_EXTENSIONS, true) || self::isBinaryData($head);
            $this->add($media ? 'php_in_media' : 'php_in_text', $relative, self::lineOf($content, stripos($content, '<?')));
        }
        if (in_array($ext, self::SCRIPT_EXTENSIONS, true) || strpos($head, '#!') === 0) {
            $this->analyzeScript($content, $relative);
        }
        if (basename($relative) === 'composer.json') {
            $json = json_decode($content, true);
            if (is_array($json) && !empty($json['scripts'])) {
                $this->add('composer_scripts', $relative, 0, implode(', ', array_keys((array) $json['scripts'])));
            }
        }
    }

    // ------------------------------------------------------------------------------------------------ binaries

    public static function isBinaryExecutable($head)
    {
        $magic4 = substr($head, 0, 4);
        if ($magic4 === "\x7fELF") {
            return true;
        }
        if (in_array($magic4, ["\xfe\xed\xfa\xce", "\xfe\xed\xfa\xcf", "\xce\xfa\xed\xfe", "\xcf\xfa\xed\xfe"], true)) {
            return true;
        }
        if (substr($head, 0, 2) === 'MZ' && strlen($head) >= 64) {
            $offset = unpack('V', substr($head, 60, 4))[1];

            return $offset > 0 && $offset < strlen($head) - 4 && substr($head, $offset, 4) === "PE\0\0";
        }

        return false;
    }

    protected static function describeBinary($head, $ext)
    {
        if (substr($head, 0, 4) === "\x7fELF") {
            return 'ELF (Linux)';
        }
        if (substr($head, 0, 2) === 'MZ') {
            return 'PE (Windows)';
        }

        return self::isBinaryExecutable($head) ? 'Mach-O (macOS)' : '.'.$ext;
    }

    protected static function isBinaryData($head)
    {
        foreach (["\x89PNG", "GIF8", "\xff\xd8\xff", '%PDF', "PK\x03\x04", "\x1f\x8b"] as $magic) {
            if (strpos($head, $magic) === 0) {
                return true;
            }
        }

        return strpos(substr($head, 0, 512), "\0") !== false;
    }

    // ------------------------------------------------------------------------------------------- shell scripts

    protected function analyzeScript($content, $relative)
    {
        $patterns = [
            'installer_pipe_shell' => '/\b(curl|wget)\b[^\n|;]*\|\s*(sudo\s+)?(ba|z|k|da)?sh\b/i',
            'installer_system'     => '/\bcrontab\b|\/etc\/cron|\/etc\/(passwd|shadow|sudoers)|\buseradd\b|\badduser\b|authorized_keys|chmod\s+(-R\s+)?0?777\b|\/dev\/tcp\/|\bnc\s+-e\b|systemctl\s+(enable|start)\b|\/etc\/(nginx|apache2|php)\//i',
            'destructive_command'  => '/\brm\s+-(rf|fr|r\s+-f)\s+(\/(\s|$|\*)|~|\$HOME|\/\*|\$\{?[A-Z_]+\}?\/?\s*$)|\bmkfs\b|\bdd\s+if=/im',
        ];
        foreach ($patterns as $rule => $re) {
            if (preg_match_all($re, $content, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as $match) {
                    $this->add($rule, $relative, self::lineOf($content, $match[1]), trim(mb_substr($match[0], 0, 80)));
                }
            }
        }
    }

    // ------------------------------------------------------------------------------------------------------ PHP

    protected static function significantTokens($code)
    {
        $out = [];
        foreach (@token_get_all($code) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_OPEN_TAG, T_CLOSE_TAG], true)) {
                    continue;
                }
                $out[] = [$t[0], $t[1], $t[2]];
            } else {
                $out[] = [$t, $t, 0];
            }
        }
        // lines of single-character tokens: those of the previous token
        $line = 1;
        foreach ($out as $i => $t) {
            if ($t[2] === 0) {
                $out[$i][2] = $line;
            } else {
                $line = $t[2];
            }
        }

        return $out;
    }

    /** Index of the matching ")" of the "(" at $open, or -1. */
    protected static function closing(array $t, $open)
    {
        if (!isset($t[$open]) || $t[$open][0] !== '(') {
            return -1;
        }
        $depth = 0;
        $n = count($t);
        for ($i = $open; $i < $n; $i++) {
            if ($t[$i][0] === '(') {
                $depth++;
            } elseif ($t[$i][0] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return -1;
    }

    /** Token ranges [from, to] of the arguments between $from and $to (commas at depth 0). */
    protected static function splitArgs(array $t, $from, $to)
    {
        $out = [];
        $depth = 0;
        $start = $from;
        for ($i = $from; $i <= $to; $i++) {
            $c = $t[$i][0];
            if ($c === '(' || $c === '[' || $c === '{' || $c === T_CURLY_OPEN || $c === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($c === ')' || $c === ']' || $c === '}') {
                $depth--;
            } elseif ($c === ',' && $depth === 0) {
                $out[] = [$start, $i - 1];
                $start = $i + 1;
            }
        }
        if ($start <= $to) {
            $out[] = [$start, $to];
        }

        return $out;
    }

    /** Literal value of argument $n if it is a single constant string, '' otherwise. */
    protected static function literalArg(array $t, array $ranges, $n)
    {
        if (!isset($ranges[$n]) || $ranges[$n][0] !== $ranges[$n][1]) {
            return '';
        }
        $tok = $t[$ranges[$n][0]];

        return $tok[0] === T_CONSTANT_ENCAPSED_STRING ? self::unquote($tok[1]) : '';
    }

    /** Lower-case name of a function token (T_STRING or PHP 8 name tokens), '' otherwise. */
    protected static function nameOf(array $tok)
    {
        $types = [T_STRING];
        foreach (['T_NAME_FULLY_QUALIFIED', 'T_NAME_QUALIFIED', 'T_NAME_RELATIVE'] as $c) {
            if (defined($c)) {
                $types[] = constant($c);
            }
        }
        if (!in_array($tok[0], $types, true)) {
            return '';
        }
        $name = strtolower(ltrim($tok[1], '\\'));

        return $name;
    }

    /** The token at $i is a call of a global function: name followed by "(", not a method, a definition or "new". */
    protected static function callAt(array $t, $i)
    {
        $name = self::nameOf($t[$i]);
        if ($name === '' || !isset($t[$i + 1]) || $t[$i + 1][0] !== '(' || strpos($name, '\\') !== false) {
            return '';
        }
        $prev = $i > 0 ? $t[$i - 1][0] : null;
        $before = [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST];
        if (defined('T_NULLSAFE_OBJECT_OPERATOR')) {
            $before[] = constant('T_NULLSAFE_OBJECT_OPERATOR');
        }
        if ($prev === T_NS_SEPARATOR && $i > 1 && self::nameOf($t[$i - 2]) !== '') {
            return ''; // Foo\exec(): a namespaced function, not PHP's
        }

        return in_array($prev, $before, true) ? '' : $name;
    }

    /** Literal values (quotes removed) and called function names of a token range. */
    protected static function argSummary(array $t, $from, $to)
    {
        $literals = [];
        $calls = [];
        $variables = false;
        for ($i = $from; $i <= $to && $i < count($t); $i++) {
            if ($t[$i][0] === T_CONSTANT_ENCAPSED_STRING || $t[$i][0] === T_ENCAPSED_AND_WHITESPACE) {
                $literals[] = self::unquote($t[$i][1]);
            } elseif ($t[$i][0] === T_VARIABLE) {
                $variables = true;
            } elseif (($name = self::callAt($t, $i)) !== '') {
                $calls[] = $name;
            } elseif ($t[$i][0] === T_DIR || $t[$i][0] === T_FILE) {
                $literals[] = '__DIR__';
            }
        }

        return ['literals' => $literals, 'text' => implode('', $literals), 'calls' => $calls, 'variables' => $variables];
    }

    protected static function unquote($s)
    {
        $s = (string) $s;
        if (strlen($s) >= 2 && ($s[0] === '"' || $s[0] === "'") && substr($s, -1) === $s[0]) {
            return substr($s, 1, -1);
        }

        return $s;
    }

    public function analyzePhp($code, $relative)
    {
        $t = self::significantTokens($code);
        $n = count($t);
        $installer = (bool) preg_match('#(^|/)Database/Migrations/#i', $relative)
            || (bool) preg_match('/^(install|setup|update|upgrade|post[-_]?install|uninstall)[^\/]*\.php$/i', basename($relative));
        $f = ['eval' => false, 'exec' => false, 'decode' => false, 'dynamic' => false, 'network' => false, 'include_dynamic' => false,
            'write' => false, 'hosts' => [], 'network_line' => 0];

        for ($i = 0; $i < $n; $i++) {
            $tok = $t[$i];
            $line = $tok[2];

            if ($tok[0] === T_EVAL) {
                $f['eval'] = true;
                $close = self::closing($t, $i + 1);
                $args = self::argSummary($t, $i + 2, $close - 1);
                if (array_intersect($args['calls'], self::DECODE_FUNCTIONS)) {
                    $this->add('eval_decode', $relative, $line, implode('(', array_values(array_intersect($args['calls'], self::DECODE_FUNCTIONS))));
                } elseif ($args['variables'] || $args['calls']) {
                    $this->add('eval_dynamic', $relative, $line);
                } else {
                    $this->add('eval_static', $relative, $line);
                }
                continue;
            }
            if ($tok[0] === '`') {
                $this->add('backtick', $relative, $line);
                $f['exec'] = true;
                // skip to the closing backtick
                for ($i++; $i < $n && $t[$i][0] !== '`'; $i++) {
                }
                continue;
            }
            if ($tok[0] === T_INCLUDE || $tok[0] === T_INCLUDE_ONCE || $tok[0] === T_REQUIRE || $tok[0] === T_REQUIRE_ONCE) {
                for ($j = $i + 1; $j < $n && $t[$j][0] !== ';'; $j++) {
                    if ($t[$j][0] === T_VARIABLE) {
                        $f['include_dynamic'] = true;
                    }
                }
                continue;
            }
            if ($tok[0] === T_HALT_COMPILER) {
                $pos = stripos($code, '__halt_compiler');
                $rest = $pos !== false ? substr($code, $pos) : '';
                $rest = (string) preg_replace('/^__halt_compiler\s*\(\s*\)\s*;?/i', '', $rest);
                if (strlen(trim($rest)) > 100) {
                    $this->add('halt_compiler_payload', $relative, $line, strlen($rest).' bytes');
                }
                break;
            }
            if ($tok[0] === T_VARIABLE && isset($t[$i + 1]) && $t[$i + 1][0] === '(') {
                $f['dynamic'] = true;
            }
            if ($tok[0] === T_CONSTANT_ENCAPSED_STRING) {
                $this->analyzeLiteral($tok[1], $relative, $line);
                // 'sys'.'tem' : a dangerous function name built from fragments
                if (isset($t[$i + 1]) && $t[$i + 1][0] === '.') {
                    $parts = [self::unquote($tok[1])];
                    $j = $i + 1;
                    while (isset($t[$j], $t[$j + 1]) && $t[$j][0] === '.' && $t[$j + 1][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $parts[] = self::unquote($t[$j + 1][1]);
                        $j += 2;
                    }
                    if (count($parts) > 1 && in_array(strtolower(implode('', $parts)), self::DANGEROUS_NAMES, true)) {
                        $this->add('constructed_name', $relative, $line, implode('', $parts));
                    }
                    $i = $j - 1;
                }
                continue;
            }

            // File::put(...) and the other writes of Laravel's File facade
            if ($tok[0] === T_STRING && in_array(strtolower($tok[1]), ['file', 'filesystem'], true) && isset($t[$i + 1], $t[$i + 2], $t[$i + 3])
                && $t[$i + 1][0] === T_DOUBLE_COLON && in_array(strtolower($t[$i + 2][1]), self::FILE_FACADE_METHODS, true) && $t[$i + 3][0] === '(') {
                $close = self::closing($t, $i + 3);
                $this->writeCall($t, $i + 4, $close - 1, $relative, $line, 'File::'.$t[$i + 2][1]);
                $f['write'] = true;
                continue;
            }
            if (self::nameOf($tok) === 'guzzlehttp\\client' || stripos($tok[1], 'GuzzleHttp') !== false
                || ($tok[0] === T_STRING && strtolower($tok[1]) === 'http' && isset($t[$i + 1]) && $t[$i + 1][0] === T_DOUBLE_COLON)) {
                $f['network'] = true;
                $f['network_line'] = $f['network_line'] ?: $line;
            }

            $name = self::callAt($t, $i);
            if ($name === '') {
                continue;
            }
            $close = self::closing($t, $i + 1);
            if ($close < 0) {
                continue;
            }
            $args = self::argSummary($t, $i + 2, $close - 1);
            $ranges = self::splitArgs($t, $i + 2, $close - 1);

            if (in_array($name, self::COMMAND_FUNCTIONS, true)) {
                $f['exec'] = true;
                $this->add('command_exec', $relative, $line, $name.'()');
                if (preg_match('/\brm\s+-(rf|fr)\b|\bmkfs\b|\bdd\s+if=|chmod\s+-R\s+0?777/i', $args['text'])) {
                    $this->add('destructive_command', $relative, $line, trim(mb_substr($args['text'], 0, 80)));
                }
            } elseif ($name === 'assert') {
                // assert('code') / assert($code) runs code on old PHP versions; assert($a === $b) does not
                if ($ranges && $ranges[0][0] === $ranges[0][1] && in_array($t[$ranges[0][0]][0], [T_CONSTANT_ENCAPSED_STRING, T_VARIABLE], true)) {
                    $this->add('assert_code', $relative, $line);
                    $f['eval'] = true;
                }
            } elseif ($name === 'create_function') {
                $this->add('create_function', $relative, $line);
                $f['eval'] = true;
            } elseif ($name === 'preg_replace') {
                $pattern = self::literalArg($t, $ranges, 0);
                if ($pattern !== '' && preg_match('/^(.).*\1([a-zA-Z]*)$/s', $pattern, $pm) && strpos($pm[2], 'e') !== false) {
                    $this->add('preg_replace_e', $relative, $line);
                    $f['eval'] = true;
                }
            } elseif (in_array($name, self::DECODE_FUNCTIONS, true)) {
                $f['decode'] = true;
                $this->add($name === 'base64_decode' ? 'base64_decode' : 'decode_function', $relative, $line, $name.'()');
                $nested = array_values(array_intersect($args['calls'], self::DECODE_FUNCTIONS));
                if ($nested) {
                    $this->add('layered_decoding', $relative, $line, $name.'('.implode('(', $nested).'(…)');
                }
            } elseif (in_array($name, ['call_user_func', 'call_user_func_array'], true)) {
                $f['dynamic'] = true;
            }

            if (in_array($name, self::NETWORK_FUNCTIONS, true)) {
                $f['network'] = true;
                $f['network_line'] = $f['network_line'] ?: $line;
            }
            $first = $ranges ? self::argSummary($t, $ranges[0][0], $ranges[0][1])['text'] : '';
            if (in_array($name, self::URL_FUNCTIONS, true) && preg_match('#^(https?|ftp)://#i', $first)) {
                $f['network'] = true;
                $f['network_line'] = $f['network_line'] ?: $line;
            }
            foreach ($args['literals'] as $literal) {
                if (preg_match('#^(?:https?|ftp)://([^/:?\#\s"\']+)#i', $literal, $hm)) {
                    $f['hosts'][strtolower($hm[1])] = $line;
                }
            }
            if (in_array($name, self::WRITE_FUNCTIONS, true)) {
                if ($name === 'fwrite' || $name === 'fputs') {
                    continue; // writes to an already opened handle: judged at fopen()
                }
                if ($name === 'fopen' && (preg_match('#^(https?|ftp)://#i', $first) || !preg_match('/[waxc+]/', self::literalArg($t, $ranges, 1)))) {
                    continue; // reading, or a URL (network, above)
                }
                $f['write'] = true;
                // destination: first argument, or second one for copy / rename / symlink / link / move_uploaded_file
                $dest = in_array($name, ['copy', 'rename', 'symlink', 'link', 'move_uploaded_file'], true) && isset($ranges[1]) ? $ranges[1] : ($ranges ? $ranges[0] : [$i + 2, $close - 1]);
                $this->writeCall($t, $dest[0], $dest[1], $relative, $line, $name.'()');
            }
        }

        // combinations in the same file
        if ($f['dynamic'] && $f['decode']) {
            $this->add('dynamic_call_decoded', $relative);
        }
        if ($f['network']) {
            $this->add('network', $relative, $f['network_line']);
            if ($f['eval'] || ($f['include_dynamic'] && $f['write'])) {
                $this->add('remote_code_exec', $relative, $f['network_line']);
            }
            if (preg_match('/(DB_PASSWORD|APP_KEY|getenv\s*\(|\$_ENV\b|[\'"]\.env[\'"]|config\s*\(\s*[\'"]database\.)/', $code)) {
                $this->add('exfiltration', $relative, $f['network_line']);
            }
            if ($installer) {
                $this->add('installer_exec', $relative, $f['network_line'], 'network request');
            }
        }
        foreach ($f['hosts'] as $host => $hostLine) {
            if (!in_array($host, self::TRUSTED_HOSTS, true) && !preg_match('/(^|\.)(localhost|example\.(com|org|net))$/', $host)) {
                $this->add('unknown_domain', $relative, $hostLine, $host);
            }
        }
        if ($installer && $f['exec']) {
            $this->add('installer_exec', $relative, 0, 'command execution');
        }
    }

    /** Obfuscation signs of one string literal. */
    protected function analyzeLiteral($raw, $relative, $line)
    {
        $value = self::unquote($raw);
        $length = strlen($value);
        if ($length >= 1000 && preg_match('/^[A-Za-z0-9+\/=\s]+$/', $value)) {
            $this->add('long_encoded_string', $relative, $line, $length.' characters');
        } elseif ($length >= 400 && self::entropy($value) > 5.3) {
            $this->add('high_entropy', $relative, $line, round(self::entropy($value), 2).' bits/char');
        }
        if (preg_match_all('/\\\\x[0-9a-f]{2}|\\\\[0-7]{3}/i', $raw) >= 40) {
            $this->add('hex_encoded_code', $relative, $line);
        }
    }

    /** Destination of a write: system folders, core, .env, another module, web server configuration. */
    protected function writeCall(array $t, $from, $to, $relative, $line, $label)
    {
        $args = self::argSummary($t, $from, $to);
        $text = $args['text'];
        $lower = strtolower($text);
        $calls = $args['calls'];
        // web server / PHP configuration first: such files often live under /etc or /var/www
        if (preg_match('/(nginx|apache2?|httpd|php\.ini|php-fpm|\.htaccess|\.user\.ini)/i', $text)) {
            $this->add('webserver_config', $relative, $line, $label.' '.mb_substr($text, 0, 80));

            return;
        }
        if (preg_match('#(^|[\s"\'(])/(etc|usr|bin|sbin|boot|root|lib|lib64|opt|var/spool/cron|var/www)(/|$)#', $text) || strpos($lower, 'cron.d') !== false) {
            $this->add('system_path_write', $relative, $line, $label.' '.mb_substr($text, 0, 80));

            return;
        }
        if (preg_match('/(^|[\/\'"])\.env(\.|$|[\'"])/', $text)) {
            $this->add('env_write', $relative, $line, $label);

            return;
        }
        $own = strtolower('Modules/'.$this->module);
        if (array_intersect($calls, ['app_path', 'config_path', 'resource_path', 'database_path'])) {
            $this->add('core_write', $relative, $line, $label.' '.implode(', ', array_intersect($calls, ['app_path', 'config_path', 'resource_path', 'database_path'])).'()');

            return;
        }
        if (in_array('base_path', $calls, true)) {
            $path = ltrim(str_replace('\\', '/', $lower), '/');
            if (preg_match('#^modules/([a-z0-9_\-]+)#', $path, $m)) {
                if ($m[1] !== strtolower($this->module)) {
                    $this->add('other_module_write', $relative, $line, $label.' Modules/'.$m[1]);
                }

                return;
            }
            if ($path === '' || preg_match('#^(app|config|routes|bootstrap|vendor|resources|public|database|artisan|composer\.)#', $path)) {
                $this->add('core_write', $relative, $line, $label.' base_path('.mb_substr($text, 0, 60).')');
            }

            return;
        }
        if (in_array('public_path', $calls, true) && preg_match('#^/?(index\.php|\.htaccess|css|js)\b#', $lower)) {
            $this->add('core_write', $relative, $line, $label.' public_path('.mb_substr($text, 0, 60).')');

            return;
        }
        if (preg_match('#^modules/([a-z0-9_\-]+)#', ltrim($lower, '/'), $m) && 'modules/'.$m[1] !== $own) {
            $this->add('other_module_write', $relative, $line, $label.' Modules/'.$m[1]);

            return;
        }
        if (substr_count($text, '../') >= 2) {
            $this->add('relative_escape', $relative, $line, $label.' '.mb_substr($text, 0, 80));
        }
    }

    public static function entropy($s)
    {
        $len = strlen($s);
        if ($len === 0) {
            return 0.0;
        }
        $h = 0.0;
        foreach (count_chars($s, 1) as $count) {
            $p = $count / $len;
            $h -= $p * log($p, 2);
        }

        return $h;
    }

    protected static function lineOf($content, $offset)
    {
        return $offset === false ? 0 : substr_count(substr($content, 0, (int) $offset), "\n") + 1;
    }
}
