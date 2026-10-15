<?php

namespace Modules\UpdateGuard\Http\Controllers;

use Illuminate\Http\Request;
use Modules\UpdateGuard\Entities\SecurityReport;
use Modules\UpdateGuard\Entities\UpdateJob;
use Modules\UpdateGuard\Services\Journal;
use Modules\UpdateGuard\Services\Paths;
use Modules\UpdateGuard\Services\Security\Providers;
use Modules\UpdateGuard\Services\Security\Secrets;
use Modules\UpdateGuard\Services\Security\SecurityReports;
use Modules\UpdateGuard\Services\Settings;

/**
 * UpdateGuard › Security Reports: list, full report, decisions of the administrator on a paused update
 * (Continue update / Install anyway / Upload and scan / Continue without upload / Cancel), manual clean-up,
 * VirusTotal connection test.
 */
class SecurityController extends BaseController
{
    const DECISIONS = ['continue', 'override', 'upload', 'skip_upload', 'cancel'];

    public function index(Request $request)
    {
        $this->admin();
        $query = SecurityReport::orderBy('id', 'desc');
        $module = (string) $request->input('module', '');
        if ($module !== '' && Paths::isModuleName($module)) {
            $query->where('module', $module);
        }
        $reports = $query->paginate(30)->appends(['module' => $module]);
        $modules = SecurityReport::distinct()->orderBy('module')->pluck('module');

        return view('updateguard::security_reports', compact('reports', 'modules', 'module'));
    }

    public function show($id)
    {
        $this->admin();
        $row = SecurityReport::findOrFail($id);
        $report = $row->data();
        $job = $row->update_id !== '' ? UpdateJob::where('update_id', $row->update_id)->first() : null;
        // decisions are possible only on the paused update and for the package that was scanned
        $waiting = $job && $job->isWaiting() && (string) $job->security_sha256 === (string) $row->sha256 && $job->security_report_id == $row->id;

        return view('updateguard::security_report', compact('row', 'report', 'job', 'waiting'));
    }

    public function decide(Request $request, $id)
    {
        $user = $this->admin();
        $row = SecurityReport::findOrFail($id);
        $report = $row->data();
        $job = $row->update_id !== '' ? UpdateJob::where('update_id', $row->update_id)->first() : null;
        $action = (string) $request->input('action');
        if (!$job || !$job->isWaiting() || !in_array($action, self::DECISIONS, true) || (string) $job->security_sha256 !== (string) $row->sha256) {
            return redirect()->route('updateguard.security.show', ['id' => $row->id])->with('ug_error', __('updateguard::ug.sec_no_decision'));
        }
        $journal = new Journal($job->update_id, $job->module, $job->from_version, $job->to_version);
        $name = $user->getFullName() ?: 'Administrator';
        if ($action === 'cancel') {
            $job->status = 'failed';
            $job->progress = 100;
            $job->message = 'cancelled by '.$name.' after the security summary';
            $job->finished_at = now();
            $job->save();
            $row->result = 'cancelled';
            $row->save();
            $journal->info('security', 'Update cancelled by '.$name.' after the security summary');

            return redirect()->route('updateguard.security.show', ['id' => $row->id])->with('ug_success', __('updateguard::ug.sec_cancelled'));
        }
        if ($action === 'override') {
            if (empty($report['overridable']) || !Settings::get('sec_allow_override') || !$request->input('understand')) {
                return redirect()->route('updateguard.security.show', ['id' => $row->id])->with('ug_error', __('updateguard::ug.sec_override_refused'));
            }
            $journal->critical('security', 'Administrator override requested by '.$name.' for a package classified '.$row->level.' ('.$row->score.'/100)');
        } elseif ($action === 'upload') {
            $journal->warning('security', 'Upload of the package to external scanners accepted by '.$name);
        } else {
            $journal->info('security', ucfirst(str_replace('_', ' ', $action)).' decided by '.$name);
        }
        $decisions = $job->decisions();
        $decisions[] = $action;
        $job->security_decision = implode(',', array_unique($decisions));
        $job->triggered_by = $name;
        $job->user_id = $user->id;
        $job->status = 'queued';
        $job->progress = 0;
        $job->message = 'resumed after the decision "'.$action.'"';
        $job->save();

        return redirect()->route('updateguard.module', ['name' => $job->module])->with('ug_job', $job->id)
            ->with('ug_success', __('updateguard::ug.queued', ['id' => $job->update_id]));
    }

    public function cleanup()
    {
        $this->admin();
        $n = SecurityReports::prune(Settings::get('sec_report_retention_days'), Settings::get('sec_report_max'));
        Providers::cache(Settings::all())->prune();

        return redirect()->route('updateguard.security')->with('ug_success', __('updateguard::ug.sec_cleaned', ['count' => $n]));
    }

    /** "Test connection": with the key typed in the form if any, otherwise the saved one. Never echoes the key. */
    public function testVirusTotal(Request $request)
    {
        $this->admin();
        $typed = trim((string) $request->input('vt_api_key', ''));
        $key = $typed !== '' && strpos($typed, '•') === false ? $typed : Secrets::get('vt_api_key');
        $result = Providers::virusTotal(Settings::all(), $key)->testConnection();

        return redirect()->to(route('updateguard.settings').'#ug-virustotal')
            ->with($result->ok ? 'ug_success' : 'ug_error', 'VirusTotal: '.($result->ok ? __('updateguard::ug.sec_connection_ok') : $result->message));
    }
}
