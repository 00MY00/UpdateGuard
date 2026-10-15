<?php

namespace Modules\UpdateGuard\Http\Controllers;

use Illuminate\Http\Request;
use Modules\UpdateGuard\Services\Journal;
use Modules\UpdateGuard\Services\Security\Secrets;
use Modules\UpdateGuard\Services\Settings;

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

        return view('updateguard::settings', compact('s', 'choices', 'lastRun', 'vtKeyMask'));
    }

    public function save(Request $request)
    {
        $user = $this->admin();
        Settings::save($request->except(['vt_api_key']));
        // API key: replaced only when a new one is typed (the field shows a mask), removed on request; stored encrypted
        $key = trim((string) $request->input('vt_api_key', ''));
        if ($request->input('vt_api_key_remove')) {
            Secrets::set('vt_api_key', '');
        } elseif ($key !== '' && strpos($key, '•') === false) {
            Secrets::set('vt_api_key', $key);
        }
        (new Journal())->info('settings', 'Settings changed by '.$user->getFullName());

        return redirect()->route('updateguard.settings')->with('ug_success', __('updateguard::ug.saved'));
    }
}
