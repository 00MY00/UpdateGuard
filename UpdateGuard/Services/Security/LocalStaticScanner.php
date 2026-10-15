<?php

namespace Modules\UpdateGuard\Services\Security;

use Modules\UpdateGuard\Services\ZipInspector;

/**
 * Local provider: heuristic static analysis (CodeAnalyzer) of an extracted package folder, or of a ZIP (extracted
 * into a temporary folder first, with the same Zip Slip protections as the update). No network. Pure PHP.
 */
class LocalStaticScanner implements SecurityScannerProviderInterface
{
    /** @var string */
    protected $module;

    /** @var array enabled checks */
    protected $checks;

    public function __construct($moduleName, array $enabledChecks)
    {
        $this->module = (string) $moduleName;
        $this->checks = $enabledChecks;
    }

    public function getName(): string
    {
        return 'Local';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function lookupHash(string $sha256): ScanResult
    {
        return ScanResult::make($this->getName(), ScanResult::NOT_AVAILABLE, 'the local scanner reads the files themselves');
    }

    public function supportsUpload(): bool
    {
        return false;
    }

    public function testConnection(): ConnectionResult
    {
        return new ConnectionResult(true, 'local');
    }

    public function scanFile(string $path): ScanResult
    {
        try {
            if (is_dir($path)) {
                $findings = (new CodeAnalyzer($this->module, $this->checks))->analyzeDirectory($path);
            } else {
                $inspection = ZipInspector::inspect($path);
                if (!$inspection['ok']) {
                    return ScanResult::make($this->getName(), ScanResult::BLOCKED, implode('; ', $inspection['errors']), ['level' => Rules::CRITICAL]);
                }
                $tmp = sys_get_temp_dir().'/ug-scan-'.getmypid().'-'.mt_rand();
                ZipInspector::extract($path, $inspection['root'], $tmp);
                try {
                    $findings = (new CodeAnalyzer($this->module, $this->checks))->analyzeDirectory($tmp);
                } finally {
                    self::removeTree($tmp);
                }
            }
        } catch (\Throwable $e) {
            return ScanResult::make($this->getName(), ScanResult::ERROR, $e->getMessage());
        }
        $max = RiskScore::maxSeverity($findings);
        $status = $max === Rules::CRITICAL ? ScanResult::BLOCKED : (Rules::ORDER[$max] >= Rules::ORDER[Rules::MEDIUM] ? ScanResult::WARNING : ScanResult::PASS);

        return ScanResult::make($this->getName(), $status, count($findings).' finding(s)', ['findings' => $findings, 'level' => $max]);
    }

    public static function removeTree($dir)
    {
        if (is_link($dir) || is_file($dir)) {
            @unlink($dir);

            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $f) {
            if ($f !== '.' && $f !== '..') {
                self::removeTree($dir.'/'.$f);
            }
        }
        @rmdir($dir);
    }
}
