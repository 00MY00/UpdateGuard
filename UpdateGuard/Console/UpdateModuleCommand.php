<?php

namespace Modules\UpdateGuard\Console;

use Illuminate\Console\Command;
use Modules\UpdateGuard\Entities\SecurityReport;
use Modules\UpdateGuard\Entities\UpdateJob;
use Modules\UpdateGuard\Services\Paths;
use Modules\UpdateGuard\Services\Security\SecurityGate;
use Modules\UpdateGuard\Services\UpdateRunner;

/**
 * Safe update from the command line. Security Gate decisions can be given in advance (automation):
 *   --yes          continue after the security summary (warnings)
 *   --upload       send an unknown package to the external scanners      --skip-upload   do not send it
 *   --override     install a package blocked by the security policy (when overridable; logged as CRITICAL)
 *   --override-compatibility   install although a related project is declared incompatible (logged as CRITICAL)
 * In an interactive terminal, the questions are asked instead.
 */
class UpdateModuleCommand extends Command
{
    protected $signature = 'updateguard:update {module : module folder name, e.g. Refresh}
        {--force : also a blocked version, or the same version again}
        {--queue : only queue the update (run by the scheduler within a minute)}
        {--zip= : install this local package (offline update) instead of the published one}
        {--sums= : SHA256SUMS file of the local package}
        {--yes : continue after the security summary without asking}
        {--upload : allow uploading an unknown package to the external scanners}
        {--skip-upload : never upload the package}
        {--override : install even if the security policy blocks it (when overridable)}
        {--override-compatibility : install even if a related project is declared incompatible (when allowed in the settings)}';

    protected $description = 'UpdateGuard: safe update of a module (SHA-256, Security Gate, staging, backup, health check, automatic rollback)';

    public function handle()
    {
        $module = (string) $this->argument('module');
        if (!Paths::isModuleName($module) || !is_dir(Paths::modules($module))) {
            $this->error('Module not found: Modules/'.$module);

            return 1;
        }
        if (UpdateJob::pendingFor($module)) {
            $this->error('An operation is already queued, running, waiting for a decision or observed for '.$module.'.');

            return 1;
        }
        $attributes = ['force' => (bool) $this->option('force'), 'triggered_by' => 'CLI'];
        if ($this->option('zip')) {
            $zip = realpath((string) $this->option('zip'));
            if (!$zip || !is_file($zip)) {
                $this->error('Package not found: '.$this->option('zip'));

                return 1;
            }
            $attributes['source_path'] = $zip;
            if ($this->option('sums')) {
                $sums = realpath((string) $this->option('sums'));
                if (!$sums) {
                    $this->error('SHA256SUMS not found: '.$this->option('sums'));

                    return 1;
                }
                $attributes['sums_path'] = $sums;
            }
        }
        // decisions given in advance (apply to whatever package is downloaded: an explicit choice of the operator)
        $decisions = [];
        foreach (['yes' => 'continue', 'upload' => 'upload', 'skip-upload' => 'skip_upload', 'override' => 'override', 'override-compatibility' => 'compat_override'] as $option => $decision) {
            if ($this->option($option)) {
                $decisions[] = $decision;
            }
        }
        $attributes['security_decision'] = implode(',', $decisions);
        $job = UpdateRunner::queue('update', $module, $attributes);
        if ($this->option('queue')) {
            $this->line($job->update_id.' queued.');

            return 0;
        }
        $runner = new UpdateRunner(Output::writer($this));
        $job = $runner->process($job);
        // paused by the Security Gate: ask in a terminal, otherwise explain how to continue
        for ($round = 0; $job->isWaiting() && $round < 3; $round++) {
            $report = $job->security_report_id ? SecurityReport::find($job->security_report_id) : null;
            $data = $report ? $report->data() : [];
            if ($data) {
                $this->line(rtrim(SecurityGate::toText($data)));
            }
            if (!$this->input->isInteractive()) {
                $this->comment($job->update_id.' is waiting for a decision: run again with --yes, --upload or --skip-upload, or decide on the Security Reports page.');

                return 1;
            }
            if (($data['decision'] ?? '') === 'upload_consent') {
                $this->warn('This package is not known by the external scanner. Uploading it shares it with the provider and its security partners.');
                $answer = $this->choice('Upload and scan?', ['cancel', 'upload', 'skip_upload'], 0);
            } else {
                $answer = $this->confirm('Continue the update?', false) ? 'continue' : 'cancel';
            }
            if ($answer === 'cancel') {
                $job->status = 'failed';
                $job->message = 'cancelled after the security summary';
                $job->finished_at = now();
                $job->save();
                $this->line($job->update_id.' cancelled.');

                return 1;
            }
            $job->security_decision = implode(',', array_unique(array_merge($job->decisions(), [$answer])));
            $job->status = 'queued';
            $job->save();
            $job = $runner->process($job);
        }
        if ($job->status === 'queued') {
            $this->comment('Another operation holds the lock: '.$job->update_id.' stays queued for the scheduler.');

            return 0;
        }
        // compatibility checks of the new version (projects related to the module)
        $report = \Modules\UpdateGuard\Services\ReportService::load(\Modules\UpdateGuard\Services\Paths::reports(), $job->update_id);
        if (!empty($report['compatibility'])) {
            $this->line(implode("\n", \Modules\UpdateGuard\Services\ReportService::compatibilityLines($report['compatibility'])));
            if (($report['compatibility']['decision']['decision'] ?? '') === 'blocked' && !empty($report['compatibility']['decision']['overridable'])) {
                $this->comment('Install anyway: run again with --override-compatibility (recorded in the logs).');
            }
        }
        $this->line($job->update_id.' '.$job->status.': '.$job->message);

        return in_array($job->status, ['successful', 'observing'], true) ? 0 : 1;
    }
}
