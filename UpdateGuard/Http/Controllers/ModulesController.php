<?php

namespace Modules\UpdateGuard\Http\Controllers;

use Illuminate\Http\Request;
use Modules\UpdateGuard\Entities\ModuleHealth;
use Modules\UpdateGuard\Entities\UpdateBackup;
use Modules\UpdateGuard\Entities\UpdateJob;
use Modules\UpdateGuard\Services\ModuleDiscoveryService;

class ModulesController extends BaseController
{
    public function index()
    {
        $this->admin();
        ModuleDiscoveryService::sync();
        $modules = ModuleHealth::orderBy('name')->get();
        $backups = UpdateBackup::selectRaw('module, max(created_at) as last')->groupBy('module')->pluck('last', 'module');

        return view('updateguard::modules', compact('modules', 'backups'));
    }

    public function show($name)
    {
        $this->admin();
        $name = $this->moduleName($name);
        ModuleDiscoveryService::sync();
        $module = ModuleHealth::named($name);
        if (!$module) {
            abort(404);
        }
        $backups = UpdateBackup::where('module', $name)->orderBy('id', 'desc')->get();
        $jobs = UpdateJob::where('module', $name)->orderBy('id', 'desc')->limit(15)->get();
        $pending = UpdateJob::pendingFor($name);
        $self = $name === ModuleDiscoveryService::SELF;

        return view('updateguard::module', compact('module', 'backups', 'jobs', 'pending', 'self'));
    }

    public function check($name)
    {
        $this->admin();

        return $this->queued($this->queue('check', $this->moduleName($name)));
    }

    public function update(Request $request, $name)
    {
        $this->admin();
        $name = $this->moduleName($name);
        if ($name === ModuleDiscoveryService::SELF) {
            return redirect()->back()->with('ug_error', __('updateguard::ug.self_update'));
        }

        return $this->queued($this->queue('update', $name, ['force' => (bool) $request->input('force')]));
    }

    public function health($name)
    {
        $this->admin();

        return $this->queued($this->queue('health', $this->moduleName($name)));
    }

    public function auto(Request $request, $name)
    {
        $this->admin();
        $module = ModuleHealth::named($this->moduleName($name));
        if ($module) {
            $module->auto_update = (bool) $request->input('auto_update');
            $module->save();
        }

        return redirect()->back()->with('ug_success', __('updateguard::ug.saved'));
    }

    /** The administrator allows a blocked version to be evaluated again (manual decision, logged). */
    public function unblock($name)
    {
        $user = $this->admin();
        $module = ModuleHealth::named($this->moduleName($name));
        if ($module && $module->blocked_version !== '') {
            (new \Modules\UpdateGuard\Services\Journal('', $module->name, $module->installed_version, $module->blocked_version))
                ->warning('unblock', 'Version '.$module->blocked_version.' unblocked by '.$user->getFullName());
            $module->blocked_version = '';
            $module->blocked_reason = '';
            $module->save();
        }

        return redirect()->back()->with('ug_success', __('updateguard::ug.saved'));
    }
}
