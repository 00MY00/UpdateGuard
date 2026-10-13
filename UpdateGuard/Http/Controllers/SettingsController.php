<?php

namespace Modules\UpdateGuard\Http\Controllers;

use Illuminate\Http\Request;
use Modules\UpdateGuard\Services\Journal;
use Modules\UpdateGuard\Services\Settings;

class SettingsController extends BaseController
{
    public function index()
    {
        $this->admin();
        $s = Settings::all();
        $choices = Settings::CHOICES;
        $lastRun = (string) \App\Option::get('updateguard.last_run', '', true, false);

        return view('updateguard::settings', compact('s', 'choices', 'lastRun'));
    }

    public function save(Request $request)
    {
        $user = $this->admin();
        Settings::save($request->all());
        (new Journal())->info('settings', 'Settings changed by '.$user->getFullName());

        return redirect()->route('updateguard.settings')->with('ug_success', __('updateguard::ug.saved'));
    }
}
