<?php

namespace Modules\UpdateGuard\Http\Controllers;

use Modules\UpdateGuard\Entities\ModuleHealth;
use Modules\UpdateGuard\Entities\UpdateJob;
use Modules\UpdateGuard\Services\ModuleDiscoveryService;
use Modules\UpdateGuard\Services\UpdateRunner;

class DashboardController extends BaseController
{
    public function index()
    {
        $this->admin();
        ModuleDiscoveryService::sync();
        $modules = ModuleHealth::orderBy('name')->get();
        $stats = [
            'modules' => $modules->count(),
            'updates' => $modules->filter(function ($m) {
                return $m->updateAvailable() && $m->blocked_version !== $m->latest_version;
            })->count(),
            'healthy' => $modules->where('health_status', 'healthy')->count(),
            'warnings' => $modules->filter(function ($m) {
                return $m->health_status === 'warning' || $m->state === 'pending_validation';
            })->count(),
            'failed' => UpdateJob::whereIn('status', ['failed', 'rollback_failed'])->where('type', 'update')->count(),
        ];
        $lastRollback = UpdateJob::where('status', 'rolled_back')->orderBy('id', 'desc')->first();
        $lastUpdate = UpdateJob::where('type', 'update')->where('status', 'successful')->whereColumn('from_version', '!=', 'to_version')->orderBy('id', 'desc')->first();
        // running, observed, and waiting for a decision (shown with "Decision required": otherwise nobody sees it)
        $running = UpdateJob::whereIn('status', array_merge(UpdateJob::ACTIVE, UpdateJob::WAITING, ['observing']))->orderBy('id')->get();
        $lastRun = (string) \App\Option::get('updateguard.last_run', '', true, false);
        // the banner of the last failure is shown until the dashboard is opened
        $alert = \Modules\UpdateGuard\Services\Ui::alert();
        if ($alert) {
            \App\Option::set('updateguard.alert_seen', $alert->id);
        }

        // versions checked in the background when the last check is more than an hour old (shown once)
        $autoCheck = \Modules\UpdateGuard\Services\Ui::autoCheck();
        if ($autoCheck) {
            $running = $running->reject(function ($job) use ($autoCheck) {
                return $job->id === $autoCheck->id;
            });
        }

        return view('updateguard::dashboard', compact('modules', 'stats', 'lastRollback', 'lastUpdate', 'running', 'lastRun', 'alert', 'autoCheck'));
    }

    public function checkAll()
    {
        $this->admin();

        return $this->queued($this->queue('check', '*'));
    }

    /** Progress of an operation (polled by Public/js/updateguard.js). */
    public function job($id)
    {
        $this->admin();
        $job = UpdateJob::findOrFail($id);
        $report = route('updateguard.report', ['updateId' => $job->update_id]);
        if ($job->type === 'scan') {
            // a security scan opens its security report (one module) or the reports of this scan (all modules)
            $reports = \Modules\UpdateGuard\Entities\SecurityReport::where('update_id', $job->update_id)->pluck('id');
            $report = count($reports) === 1
                ? route('updateguard.security.show', ['id' => $reports[0]])
                : route('updateguard.security', ['update_id' => $job->update_id]);
        }

        return response()->json([
            'type'      => $job->type,
            'id'        => $job->id,
            'update_id' => $job->update_id,
            'module'    => $job->module,
            'status'    => $job->status,
            'label'     => __('updateguard::ug.job_'.$job->status),
            'progress'  => $job->status === 'queued' ? 0 : (isset(UpdateRunner::PROGRESS[$job->status]) ? UpdateRunner::PROGRESS[$job->status] : 100),
            'message'   => (string) $job->message,
            'finished'  => $job->isFinished(),
            'report'    => $report,
            // paused by the Security Gate: the page opens the security summary where the administrator decides
            'decision'  => $job->isWaiting() && $job->security_report_id ? route('updateguard.security.show', ['id' => $job->security_report_id]) : '',
        ]);
    }
}
