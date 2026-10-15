<?php

namespace Modules\UpdateGuard\Http\Controllers;

use Illuminate\Http\Request;
use Modules\UpdateGuard\Services\Journal;
use Modules\UpdateGuard\Services\Security\Providers;
use Modules\UpdateGuard\Services\Security\Secrets;
use Modules\UpdateGuard\Services\Settings;

/**
 * Settings page. Saved automatically by Public/js/updateguard.js on every change (AJAX, JSON answer); the "Save"
 * button remains for browsers without JavaScript. A new VirusTotal API key is tested before it is saved.
 */
class SettingsController extends BaseController
{
    public function index()
    {
        $this->admin();
        $s = Settings::all();
        $choices = Settings::CHOICES;
        $lastRun = (string) \App\Option::get('updateguard.last_run', '', true, false);

        // only a mask of the saved key is ever sent to the page
        $vtKeyMask = Secrets::mask('vt_api_key');
        $limiter = Providers::virusTotalLimiter($s);
        $vtUsage = $limiter ? $limiter->usage() : null;

        return view('updateguard::settings', compact('s', 'choices', 'lastRun', 'vtKeyMask', 'vtUsage'));
    }

    public function save(Request $request)
    {
        $user = $this->admin();
        Settings::save($request->except(['vt_api_key']));
        $key = $this->saveApiKey($request);
        (new Journal())->info('settings', 'Settings changed by '.$user->getFullName());

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'ok'      => true,
                'message' => __('updateguard::ug.saved_auto'),
                'key'     => $key,
                'mask'    => Secrets::mask('vt_api_key'),
            ]);
        }
        $redirect = redirect()->route('updateguard.settings');
        if ($key && !$key['saved']) {
            return $redirect->with('ug_error', 'VirusTotal: '.$key['message']);
        }

        return $redirect->with('ug_success', __('updateguard::ug.saved').($key ? ' VirusTotal: '.$key['message'] : ''));
    }

    /**
     * API key: replaced only when a new one is typed (the field shows a mask), removed on request, stored encrypted.
     * A new key is tested first: refused by VirusTotal => not saved; VirusTotal unreachable or rate limit of the
     * free API => saved with a warning (it could not be verified now).
     * @return array|null ['saved' => bool, 'ok' => bool, 'message' => string] when a key was typed or removed
     */
    protected function saveApiKey(Request $request)
    {
        if ($request->input('vt_api_key_remove')) {
            Secrets::set('vt_api_key', '');

            return ['saved' => true, 'ok' => true, 'message' => __('updateguard::ug.vt_key_removed')];
        }
        $key = trim((string) $request->input('vt_api_key', ''));
        if ($key === '' || strpos($key, '•') !== false) {
            return null;
        }
        $test = Providers::virusTotal(Settings::all(), $key)->testConnection();
        $unverified = !$test->ok && preg_match('/^(unreachable|rate limit|daily quota|monthly quota)/i', $test->message);
        if (!$test->ok && !$unverified) {
            return ['saved' => false, 'ok' => false, 'message' => __('updateguard::ug.vt_key_refused', ['reason' => $test->message])];
        }
        Secrets::set('vt_api_key', $key);

        return $test->ok
            ? ['saved' => true, 'ok' => true, 'message' => __('updateguard::ug.vt_key_ok')]
            : ['saved' => true, 'ok' => false, 'message' => __('updateguard::ug.vt_key_unverified', ['reason' => $test->message])];
    }
}
