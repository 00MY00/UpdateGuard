@extends('updateguard::layout')

@section('ug_content')
    @if ($alert)
        <div class="alert alert-danger">
            × {{ __('updateguard::ug.alert_failed', ['module' => $alert->module, 'version' => $alert->to_version, 'status' => __('updateguard::ug.job_'.$alert->status)]) }}
            <a href="{{ route('updateguard.report', ['updateId' => $alert->update_id]) }}">{{ __('updateguard::ug.view_report') }}</a>
        </div>
    @endif
    @if ($lastRun === '' || strtotime($lastRun) < time() - 600)
        <div class="alert alert-warning">! {{ __('updateguard::ug.scheduler_missing') }}</div>
    @endif

    <div class="ug-toolbar">
        <form method="POST" action="{{ route('updateguard.check_all') }}" class="ug-inline">
            {{ csrf_field() }}
            <button type="submit" class="btn btn-primary">{{ __('updateguard::ug.check_all') }}</button>
        </form>
        <form method="POST" action="{{ route('updateguard.scan_all') }}" class="ug-inline" data-ug-confirm="{{ __('updateguard::ug.sec_scan_all_confirm') }}">
            {{ csrf_field() }}
            <button type="submit" class="btn btn-default">🛡 {{ __('updateguard::ug.sec_scan_all') }}</button>
        </form>
    </div>

    <div class="ug-stats">
        <div class="ug-stat"><span class="ug-stat-value">{{ $stats['modules'] }}</span><span class="ug-stat-label">{{ __('updateguard::ug.stat_modules') }}</span></div>
        <div class="ug-stat"><span class="ug-stat-value">↑ {{ $stats['updates'] }}</span><span class="ug-stat-label">{{ __('updateguard::ug.stat_updates') }}</span></div>
        <div class="ug-stat"><span class="ug-stat-value">✓ {{ $stats['healthy'] }}</span><span class="ug-stat-label">{{ __('updateguard::ug.stat_healthy') }}</span></div>
        <div class="ug-stat"><span class="ug-stat-value">! {{ $stats['warnings'] }}</span><span class="ug-stat-label">{{ __('updateguard::ug.stat_warnings') }}</span></div>
        <div class="ug-stat"><span class="ug-stat-value">× {{ $stats['failed'] }}</span><span class="ug-stat-label">{{ __('updateguard::ug.stat_failed') }}</span></div>
    </div>

    <div class="ug-meta">
        <div><strong>{{ __('updateguard::ug.last_update') }}:</strong>
            @if ($lastUpdate) {{ $lastUpdate->module }} {{ $lastUpdate->from_version }} → {{ $lastUpdate->to_version }} ({{ $lastUpdate->finished_at }}) @else — @endif</div>
        <div><strong>{{ __('updateguard::ug.last_rollback') }}:</strong>
            @if ($lastRollback) ↺ {{ $lastRollback->module }} {{ $lastRollback->to_version }} → {{ $lastRollback->from_version }} ({{ $lastRollback->finished_at }}) @else — @endif</div>
    </div>

    @foreach ($running as $job)
        @include('updateguard::partials.progress', ['jobId' => $job->id])
    @endforeach

    @include('updateguard::partials.modules_table', ['modules' => $modules, 'backups' => null])
@endsection
