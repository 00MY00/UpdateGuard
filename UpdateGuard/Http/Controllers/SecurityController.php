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
        // reports of one operation (e.g. "Scan all modules")
        $updateId = (string) $request->input('update_id', '');
        if (\Modules\UpdateGuard\Services\ReportService::isUpdateId($updateId)) {
            $query->where('update_id', $updateId);
        }
        $reports = $query->paginate(30)->appends(['module' => $module, 'update_id' => $updateId]);
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

    /** "Scan all modules" (dashboard): one queued job, one security report per module. */
    public function scanAll(Request $request)
    {
        $user = $this->admin();
        $attributes = [];
        if ($request->input('upload')) {
            // "Upload all unknown packages": packages already known are taken from the cache (no request); only the
            // unknown ones are sent, after the privacy warning confirmed on the page
            $attributes['security_decision'] = 'upload';
            (new Journal('', '*'))->warning('security', 'Upload of the unknown packages to external scanners accepted by '.($user->getFullName() ?: 'Administrator'));
        }

        return $this->queued($this->queue('scan', '*', $attributes));
    }

    /** "Security scan" of one module (module page). */
    public function scanModule($name)
    {
        $this->admin();

        return $this->queued($this->queue('scan', $this->moduleName($name)));
    }

    /**
     * "Upload and scan" from a report whose package is unknown to an external scanner: a new scan of the published
     * package with the upload accepted (privacy warning shown and confirmed on the page, decision logged).
     */
    public function upload($id)
    {
        $user = $this->admin();
        $row = SecurityReport::findOrFail($id);
        $name = $this->moduleName($row->module);
        (new Journal('', $name, $row->from_version, $row->to_version))
            ->warning('security', 'Upload of the package to external scanners accepted by '.($user->getFullName() ?: 'Administrator'));

        return $this->queued($this->queue('scan', $name, ['security_decision' => 'upload']));
    }

    /**
     * Sends the official UpdateGuard.zip of the installed version to VirusTotal (it did not know it): a public
     * package, checked against the release's SHA256SUMS before it is sent (SelfCheck).
     */
    public function uploadSelf()
    {
        $user = $this->admin();
        $name = \Modules\UpdateGuard\Services\ModuleDiscoveryService::SELF;
        (new Journal('', $name, '', ''))
            ->info('security', 'Upload of the official UpdateGuard package to VirusTotal accepted by '.($user->getFullName() ?: 'Administrator'));

        return $this->queued($this->queue('scan', $name, ['security_decision' => 'upload']));
    }

    /**
     * "Trust these findings": the administrator checked them (e.g. VirusTotal reported nothing). Reason and
     * confirmation required; never for integrity / archive / external results (SecurityGate::trustable()).
     */
    public function trust(Request $request, $id)
    {
        $user = $this->admin();
        $row = SecurityReport::findOrFail($id);
        $reason = trim((string) $request->input('reason', ''));
        if (!Settings::get('sec_allow_trust') || $reason === '' || !$request->input('understand')) {
            return redirect()->route('updateguard.security.show', ['id' => $row->id])->with('ug_error', __('updateguard::ug.trust_refused'));
        }
        $data = $row->data();
        foreach ((array) ($data['external'] ?? []) as $e) {
            if (($e['status'] ?? '') === 'BLOCKED') {
                // an external scanner flagged the package itself: not something to trust from here
                return redirect()->route('updateguard.security.show', ['id' => $row->id])->with('ug_error', __('updateguard::ug.trust_refused_external'));
            }
        }
        $n = \Modules\UpdateGuard\Services\Security\Trusts::trustReport($row, $user, $reason);

        return redirect()->route('updateguard.module', ['name' => $row->module])->with('ug_success', __('updateguard::ug.trust_done', ['count' => $n]));
    }

    public function revokeTrust($id)
    {
        $user = $this->admin();
        $trust = \Modules\UpdateGuard\Entities\Trust::findOrFail($id);
        if ($trust->revoked_at === null) {
            \Modules\UpdateGuard\Services\Security\Trusts::revoke($trust, $user);
        }

        return redirect()->back()->with('ug_success', __('updateguard::ug.trust_revoked'));
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
