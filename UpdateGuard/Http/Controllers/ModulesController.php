<?php

namespace Modules\UpdateGuard\Http\Controllers;

use Illuminate\Http\Request;
use Modules\UpdateGuard\Entities\ModuleHealth;
use Modules\UpdateGuard\Entities\UpdateBackup;
use Modules\UpdateGuard\Entities\UpdateJob;
use Modules\UpdateGuard\Services\Compatibility\Compatibility;
use Modules\UpdateGuard\Services\Compatibility\CompatibilityChecker;
use Modules\UpdateGuard\Services\ModuleDiscoveryService;

class ModulesController extends BaseController
{
    public function index()
    {
        $this->admin();
        ModuleDiscoveryService::sync();
        $modules = ModuleHealth::orderBy('name')->get();
        $backups = UpdateBackup::selectRaw('module, max(created_at) as last')->groupBy('module')->pluck('last', 'module');

        $autoCheck = \Modules\UpdateGuard\Services\Ui::autoCheck();

        return view('updateguard::modules', compact('modules', 'backups', 'autoCheck'));
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
        $lastScan = \Modules\UpdateGuard\Entities\SecurityReport::where('module', $name)->orderBy('id', 'desc')->first();

        $trusts = \Modules\UpdateGuard\Entities\Trust::active()->where('module', $name)->orderBy('id', 'desc')->get();

        // project compatibility: installed version, latest version (when newer), modules that declare this one
        $installed = ModuleDiscoveryService::find($name);
        $compat = $compatLatest = null;
        $declaredBy = [];
        if ($installed) {
            try {
                $compat = Compatibility::forInstalled($installed);
                if ($module->updateAvailable() && !$self) {
                    $compatLatest = Compatibility::preview($installed, $module);
                }
                $declaredBy = Compatibility::declaredBy($name);
            } catch (\Throwable $e) {
                // optional information: never breaks the page
            }
        }

        return view('updateguard::module', compact('module', 'backups', 'jobs', 'pending', 'self', 'lastScan', 'trusts', 'compat', 'compatLatest', 'declaredBy'));
    }

    public function check($name)
    {
        $this->admin();

        return $this->queued($this->queue('check', $this->moduleName($name)));
    }

    /**
     * Before an update: compatibility checks of the new version (release interop.json read by the last check, else
     * the installed metadata), then Cancel / Update / Install anyway. The package is checked again after download.
     */
    public function confirmUpdate($name)
    {
        $this->admin();
        $name = $this->moduleName($name);
        if ($name === ModuleDiscoveryService::SELF) {
            return redirect()->to(\Modules\UpdateGuard\Services\Ui::freescoutModulesUrl());
        }
        ModuleDiscoveryService::sync();
        $module = ModuleHealth::named($name);
        $installed = ModuleDiscoveryService::find($name);
        if (!$module || !$installed) {
            abort(404);
        }
        $compat = Compatibility::preview($installed, $module);
        $compat['decision'] = CompatibilityChecker::decide($compat, Compatibility::settings(), false);
        $pending = UpdateJob::pendingFor($name);

        return view('updateguard::update_confirm', compact('module', 'compat', 'pending'));
    }

    public function update(Request $request, $name)
    {
        $this->admin();
        $name = $this->moduleName($name);
        if ($name === ModuleDiscoveryService::SELF) {
            return redirect()->back()->with('ug_error', __('updateguard::ug.self_update'));
        }

        $attributes = ['force' => (bool) $request->input('force')];
        $decisions = [];
        // "Install anyway" after a compatibility block: deliberate (checkbox), logged as CRITICAL, manual only
        if ($request->input('compat_override')) {
            if (!\Modules\UpdateGuard\Services\Settings::get('compat_allow_override') || !$request->input('understand')) {
                return redirect()->back()->with('ug_error', __('updateguard::ug.compat_override_refused'));
            }
            $decisions[] = 'compat_override';
            $user = auth()->user();
            (new \Modules\UpdateGuard\Services\Journal('', $name, '', ''))->critical('compatibility',
                'Compatibility override ("Install anyway") requested by '.($user->getFullName() ?: 'Administrator'));
        }
        // "Install anyway" after a Security Gate block: deliberate action (checkbox + confirmation), logged, valid
        // only for the package that was scanned (same SHA-256)
        if ($request->input('override_report')) {
            $report = \Modules\UpdateGuard\Entities\SecurityReport::find((int) $request->input('override_report'));
            $data = $report ? $report->data() : [];
            if (!$report || $report->module !== $name || empty($data['overridable']) || !\Modules\UpdateGuard\Services\Settings::get('sec_allow_override')
                || !$request->input('understand')) {
                return redirect()->back()->with('ug_error', __('updateguard::ug.sec_override_refused'));
            }
            $decisions[] = 'override';
            $attributes['security_sha256'] = $report->sha256;
            $attributes['force'] = true;
            $user = auth()->user();
            (new \Modules\UpdateGuard\Services\Journal('', $name, $report->from_version, $report->to_version))->critical('security',
                'Administrator override requested by '.$user->getFullName().' for a package classified '.$report->level.' ('.$report->score.'/100, SHA-256 '.$report->sha256.')');
        }

        if ($decisions) {
            $attributes['security_decision'] = implode(',', $decisions);
        }

        return $this->queued($this->queue('update', $name, $attributes));
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
