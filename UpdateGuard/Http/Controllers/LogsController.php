<?php

namespace Modules\UpdateGuard\Http\Controllers;

use Illuminate\Http\Request;
use Modules\UpdateGuard\Entities\UpdateJob;
use Modules\UpdateGuard\Entities\UpdateLog;
use Modules\UpdateGuard\Services\Journal;
use Modules\UpdateGuard\Services\Paths;
use Modules\UpdateGuard\Services\ReportService;

class LogsController extends BaseController
{
    public function index(Request $request)
    {
        $this->admin();
        $f = [
            'module'    => (string) $request->input('module', ''),
            'date'      => (string) $request->input('date', ''),
            'severity'  => (string) $request->input('severity', ''),
            'status'    => (string) $request->input('status', ''),
            'update_id' => (string) $request->input('update_id', ''),
            'trigger'   => (string) $request->input('trigger', ''),
        ];
        $logs = UpdateLog::orderBy('id', 'desc');
        $jobs = UpdateJob::orderBy('id', 'desc');
        if ($f['module'] !== '' && Paths::isModuleName($f['module'])) {
            $logs->where('module', $f['module']);
            $jobs->where('module', $f['module']);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['date'])) {
            $logs->whereDate('created_at', $f['date']);
            $jobs->whereDate('created_at', $f['date']);
        }
        if (in_array($f['severity'], Journal::LEVELS, true)) {
            $logs->where('level', $f['severity']);
        }
        if (in_array($f['status'], array_merge(UpdateJob::ACTIVE, UpdateJob::FINISHED, ['observing']), true)) {
            $jobs->where('status', $f['status']);
            $logs->whereIn('update_id', UpdateJob::where('status', $f['status'])->pluck('update_id'));
        }
        if (ReportService::isUpdateId($f['update_id'])) {
            $logs->where('update_id', $f['update_id']);
            $jobs->where('update_id', $f['update_id']);
        }
        if ($f['trigger'] === 'automatic' || $f['trigger'] === 'manual') {
            $jobs->where('automatic', $f['trigger'] === 'automatic');
            $logs->whereIn('update_id', UpdateJob::where('automatic', $f['trigger'] === 'automatic')->pluck('update_id'));
        }
        $logs = $logs->paginate(50, ['*'], 'logs_page')->appends($f);
        $jobs = $jobs->paginate(20, ['*'], 'jobs_page')->appends($f);
        $modules = UpdateJob::distinct()->orderBy('module')->pluck('module')->reject(function ($m) {
            return $m === '*';
        });

        return view('updateguard::logs', compact('logs', 'jobs', 'f', 'modules'));
    }

    public function report($updateId)
    {
        $this->admin();
        $report = ReportService::load(Paths::reports(), $updateId);
        $job = UpdateJob::where('update_id', $updateId)->first();
        if (!$report && !$job) {
            abort(404);
        }
        $logs = UpdateLog::where('update_id', $updateId)->orderBy('id')->get();

        return view('updateguard::report', compact('report', 'job', 'logs', 'updateId'));
    }

    public function download($updateId, $format)
    {
        $this->admin();
        $report = ReportService::load(Paths::reports(), $updateId);
        if (!$report) {
            abort(404);
        }
        $types = ['txt' => 'text/plain', 'json' => 'application/json', 'html' => 'text/html'];
        $body = $format === 'json' ? ReportService::toJson($report) : ($format === 'html' ? ReportService::toHtml($report) : ReportService::toText($report));

        return response($body, 200, [
            'Content-Type'        => $types[$format].'; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$updateId.'.'.$format.'"',
        ]);
    }

    public function delete($updateId)
    {
        $this->admin();
        $job = UpdateJob::where('update_id', $updateId)->first();
        if ($job && !$job->isFinished()) {
            return redirect()->back()->with('ug_error', __('updateguard::ug.report_in_use'));
        }
        @unlink(Paths::reports().'/'.$updateId.'.json');
        UpdateLog::where('update_id', $updateId)->delete();

        return redirect()->route('updateguard.logs')->with('ug_success', __('updateguard::ug.deleted'));
    }
}
