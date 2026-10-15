<?php

namespace Modules\UpdateGuard\Services\Security;

use Modules\UpdateGuard\Services\Paths;

/**
 * External providers enabled in the settings, plus those added by other modules:
 *
 *   \Eventy::addFilter('updateguard.security_providers', function ($providers, $settings) {
 *       $providers[] = new MyScanner();   // implements SecurityScannerProviderInterface
 *       return $providers;
 *   }, 20, 2);
 *
 * Anything that does not implement the interface is ignored.
 */
class Providers
{
    /** @return SecurityScannerProviderInterface[] */
    public static function external(array $s)
    {
        $providers = [];
        if (!empty($s['vt_enabled'])) {
            $providers[] = self::virusTotal($s);
        }
        try {
            $providers = (array) \Eventy::filter('updateguard.security_providers', $providers, $s);
        } catch (\Throwable $e) {
        }

        return array_values(array_filter($providers, function ($p) {
            return $p instanceof SecurityScannerProviderInterface;
        }));
    }

    public static function virusTotal(array $s, $apiKey = null)
    {
        return new VirusTotalScanner($apiKey !== null ? $apiKey : Secrets::get('vt_api_key'), self::http((int) ($s['vt_timeout'] ?? 30)), [
            'warning'  => (int) ($s['vt_warning_min'] ?? 1),
            'high'     => (int) ($s['vt_high_min'] ?? 3),
            'critical' => (int) ($s['vt_critical_min'] ?? 6),
            'block'    => (int) ($s['vt_block_threshold'] ?? 3),
        ]);
    }

    public static function cache(array $s)
    {
        return new ScanCache(Paths::base('security/cache'), (int) ($s['sec_cache_days'] ?? 7));
    }

    /** HTTP client of the providers: Guzzle with FreeScout's proxy / SSL options, never throws on HTTP errors. */
    public static function http($timeout = 30)
    {
        return function ($method, $url, array $options) use ($timeout) {
            $guzzle = ['timeout' => max(5, $timeout), 'connect_timeout' => 10, 'http_errors' => false, 'headers' => $options['headers'] ?? []];
            if (class_exists(\App\Misc\Helper::class) && method_exists(\App\Misc\Helper::class, 'setGuzzleDefaultOptions')) {
                $guzzle = \App\Misc\Helper::setGuzzleDefaultOptions($guzzle);
            }
            if (!empty($options['multipart'])) {
                $guzzle['timeout'] = max(120, $timeout);
                $guzzle['multipart'] = array_map(function ($part) {
                    return ['name' => $part['name'], 'contents' => fopen($part['path'], 'rb'), 'filename' => $part['filename']];
                }, $options['multipart']);
            }
            $response = (new \GuzzleHttp\Client())->request($method, $url, $guzzle);

            return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
        };
    }
}
