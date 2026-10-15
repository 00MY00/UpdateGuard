<?php

namespace Modules\UpdateGuard\Services\Security;

/**
 * API keys of external providers, encrypted with Laravel's encrypter (APP_KEY of FreeScout, AES-256) in FreeScout's
 * options table. The full key is never sent back to a page: only a mask with its last 4 characters.
 */
class Secrets
{
    const PREFIX = 'updateguard.secret.';

    public static function get($name)
    {
        try {
            $stored = (string) \App\Option::get(self::PREFIX.$name, '', true, false);

            return $stored === '' ? '' : (string) decrypt($stored);
        } catch (\Throwable $e) {
            // APP_KEY changed or value damaged: as if no key was saved
            return '';
        }
    }

    public static function set($name, $value)
    {
        $value = trim((string) $value);
        \App\Option::set(self::PREFIX.$name, $value === '' ? '' : encrypt($value));
    }

    public static function has($name)
    {
        return self::get($name) !== '';
    }

    /** "••••••••ab12" or '' */
    public static function mask($name)
    {
        $value = self::get($name);

        return $value === '' ? '' : str_repeat('•', 8).substr($value, -4);
    }
}
