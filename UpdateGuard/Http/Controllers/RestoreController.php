<?php

namespace Modules\UpdateGuard\Http\Controllers;

use Illuminate\Http\Request;
use Modules\UpdateGuard\Entities\UpdateBackup;
use Modules\UpdateGuard\Entities\UpdateJob;
use Modules\UpdateGuard\Services\BackupService;
use Modules\UpdateGuard\Services\ModuleDiscoveryService;
use Modules\UpdateGuard\Services\Paths;

class RestoreController extends BaseController
{
    public function index(Request $request)
    {
        $this->admin();
        $query = UpdateBackup::orderBy('id', 'desc');
        $module = (string) $request->input('module', '');
        if ($module !== '' && Paths::isModuleName($module)) {
            $query->where('module', $module);
        }
        $backups = $query->paginate(30);
        $installed = [];
        foreach (ModuleDiscoveryService::installed() as $name => $m) {
            $installed[$name] = $m['version'];
        }
        $modules = UpdateBackup::distinct()->orderBy('module')->pluck('module');

        return view('updateguard::restore', compact('backups', 'installed', 'modules', 'module'));
    }

    /** Confirmation page: current version, version to restore, backup made first. */
    public function confirm($id)
    {
        $this->admin();
        $backup = UpdateBackup::findOrFail($id);
        $current = (string) (ModuleDiscoveryService::manifest(Paths::modules($backup->module))['version'] ?? '');
        $valid = BackupService::verify($backup);
        $pending = UpdateJob::pendingFor($backup->module);
        $self = $backup->module === ModuleDiscoveryService::SELF;

        return view('updateguard::restore_confirm', compact('backup', 'current', 'valid', 'pending', 'self'));
    }

    public function restore($id)
    {
        $this->admin();
        $backup = UpdateBackup::findOrFail($id);
        if ($backup->module === ModuleDiscoveryService::SELF || !is_dir(Paths::modules($backup->module))) {
            return redirect()->route('updateguard.restorations')->with('ug_error', __('updateguard::ug.not_restorable'));
        }
        $job = $this->queue('restore', $backup->module, ['backup_id' => $backup->id, 'to_version' => $backup->version]);
        if (!$job) {
            return redirect()->back()->with('ug_error', __('updateguard::ug.already_pending'));
        }

        return redirect()->route('updateguard.module', ['name' => $backup->module])->with('ug_job', $job->id)
            ->with('ug_success', __('updateguard::ug.queued', ['id' => $job->update_id]));
    }

    public function download($id)
    {
        $this->admin();
        $backup = UpdateBackup::findOrFail($id);
        if (!$backup->exists()) {
            abort(404);
        }
        $file = BackupService::zip($backup);

        return response()->download($file, $backup->module.'-'.$backup->version.'-backup-'.$backup->id.'.zip')->deleteFileAfterSend(true);
    }

    public function delete($id)
    {
        $this->admin();
        $backup = UpdateBackup::findOrFail($id);
        if (UpdateJob::where('backup_id', $backup->id)->whereIn('status', array_merge(UpdateJob::ACTIVE, ['observing']))->exists()) {
            return redirect()->back()->with('ug_error', __('updateguard::ug.backup_in_use'));
        }
        BackupService::delete($backup);
        (new \Modules\UpdateGuard\Services\Journal('', $backup->module, $backup->version))
            ->info('backup', 'Backup '.$backup->path.' deleted by '.auth()->user()->getFullName());

        return redirect()->back()->with('ug_success', __('updateguard::ug.deleted'));
    }
}
