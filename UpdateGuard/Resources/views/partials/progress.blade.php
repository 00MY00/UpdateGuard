{{-- Progress of a queued / running operation, refreshed by updateguard.js (GET jobs/{id}). --}}
<div class="panel panel-default ug-progress" data-ug-job="{{ route('updateguard.job', ['id' => $jobId]) }}">
    <div class="panel-body">
        <div class="ug-progress-line">
            <strong class="ug-progress-label">● {{ __('updateguard::ug.job_queued') }}</strong>
            <span class="ug-progress-message text-help">{{ __('updateguard::ug.scheduler_hint') }}</span>
        </div>
        <div class="progress">
            <div class="progress-bar" role="progressbar" style="width: 2%" aria-valuemin="0" aria-valuemax="100" aria-valuenow="2"></div>
        </div>
        <a class="ug-progress-report hidden" href="#">{{ __('updateguard::ug.view_report') }}</a>
    </div>
</div>
