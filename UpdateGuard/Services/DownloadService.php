<?php

namespace Modules\UpdateGuard\Services;

/**
 * HTTPS downloads (Guzzle, with FreeScout's proxy / SSL options when available: App\Misc\Helper::setGuzzleDefaultOptions).
 * Plain http:// and every other scheme are refused: an archive must not be swapped on the way.
 */
class DownloadService
{
    /** @var int */
    protected $timeout;

    public function __construct($timeout = null)
    {
        $this->timeout = $timeout === null ? max(5, (int) Settings::get('download_timeout')) : (int) $timeout;
    }

    public static function isAllowedUrl($url)
    {
        return is_string($url) && preg_match('#^https://[^\s/$.?\#][^\s]*$#i', $url) === 1;
    }

    protected function options(array $extra = [])
    {
        $options = ['timeout' => $this->timeout, 'connect_timeout' => min(15, $this->timeout), 'http_errors' => false];
        if (class_exists(\App\Misc\Helper::class) && method_exists(\App\Misc\Helper::class, 'setGuzzleDefaultOptions')) {
            $options = \App\Misc\Helper::setGuzzleDefaultOptions($options);
        }

        return array_merge($options, $extra);
    }

    /** Body of a small text file (latestVersionUrl, SHA256SUMS); at most 1 MB. */
    public function text($url)
    {
        if (!self::isAllowedUrl($url)) {
            throw new \RuntimeException('only https:// addresses are accepted: '.$url);
        }
        $response = (new \GuzzleHttp\Client())->request('GET', $url, $this->options());
        $code = $response->getStatusCode();
        if ($code !== 200) {
            throw new \RuntimeException('HTTP '.$code.': '.$url, $code);
        }

        return (string) $response->getBody()->read(1048576);
    }

    /** Downloads to a file; returns its size. */
    public function file($url, $to)
    {
        if (!self::isAllowedUrl($url)) {
            throw new \RuntimeException('only https:// addresses are accepted: '.$url);
        }
        $response = (new \GuzzleHttp\Client())->request('GET', $url, $this->options(['sink' => $to]));
        $code = $response->getStatusCode();
        if ($code !== 200) {
            @unlink($to);
            throw new \RuntimeException('HTTP '.$code.': '.$url, $code);
        }
        clearstatcache(true, $to);

        return (int) @filesize($to);
    }

    /** SHA256SUMS address of a module: module.json "sha256sumsUrl", or next to the ZIP. */
    public static function sumsUrl(array $manifest)
    {
        if (!empty($manifest['sha256sumsUrl']) && is_string($manifest['sha256sumsUrl'])) {
            return $manifest['sha256sumsUrl'];
        }
        $zip = isset($manifest['latestVersionZipUrl']) ? (string) $manifest['latestVersionZipUrl'] : '';
        if ($zip === '') {
            return '';
        }
        $path = parse_url($zip, PHP_URL_PATH);

        return $path ? substr($zip, 0, strrpos($zip, '/') + 1).'SHA256SUMS' : '';
    }

    /** File name under which the ZIP is listed in SHA256SUMS. */
    public static function zipName(array $manifest)
    {
        $path = (string) parse_url((string) ($manifest['latestVersionZipUrl'] ?? ''), PHP_URL_PATH);

        return basename($path);
    }
}
