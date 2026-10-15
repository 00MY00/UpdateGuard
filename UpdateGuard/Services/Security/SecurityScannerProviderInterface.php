<?php

namespace Modules\UpdateGuard\Services\Security;

/**
 * A security scanner used by the Security Gate before a module update is installed.
 *
 * Initial providers: LocalStaticScanner (no network) and VirusTotalScanner (optional, off by default). Other modules
 * can add providers (MetaDefender, ClamAV, a custom REST scanner...) with the filter "updateguard.security_providers"
 * (see README). A provider must never throw: every problem is returned as a ScanResult with status ERROR, and
 * UpdateGuard decides what to do from the security policy.
 */
interface SecurityScannerProviderInterface
{
    /** Short stable name, shown in reports and used as cache key ("VirusTotal"). */
    public function getName(): string;

    /** Enabled and has everything it needs (API key...). */
    public function isConfigured(): bool;

    /** Lookup by SHA-256 without sending the file. NOT_AVAILABLE with $unknown = true when the hash is unknown. */
    public function lookupHash(string $sha256): ScanResult;

    /** Scan of the file itself (upload for remote providers: only called after the administrator agreed). */
    public function scanFile(string $path): ScanResult;

    /** The provider can receive the file (privacy: never used without consent or explicit setting). */
    public function supportsUpload(): bool;

    public function testConnection(): ConnectionResult;
}
