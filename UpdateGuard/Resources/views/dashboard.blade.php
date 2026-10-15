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

    @php $unknownVt = \Modules\UpdateGuard\Services\Ui::unknownToScanners(); @endphp
    @if ($unknownVt->isNotEmpty())
        {{-- packages VirusTotal does not know yet: sent only on the administrator's click (privacy) --}}
        <div class="panel panel-default ug-unknown-vt">
            <div class="panel-heading"><strong>{{ __('updateguard::ug.vt_unknown_title') }}</strong></div>
            <div class="panel-body">
                <p class="text-help">{{ __('updateguard::ug.vt_unknown_text', ['count' => $unknownVt->count()]) }}</p>
                <ul class="ug-unknown-list">
                    @foreach ($unknownVt as $r)
                        <li>
                            <span><strong>{{ $r->module }}</strong> {{ $r->to_version }} <small class="text-help">· {{ $r->created_at }} · <a href="{{ route('updateguard.security.show', ['id' => $r->id]) }}">{{ __('updateguard::ug.view_report') }}</a></small></span>
                            <form method="POST" action="{{ route('updateguard.security.upload', ['id' => $r->id]) }}" class="ug-inline" data-ug-confirm="{{ __('updateguard::ug.sec_upload_text') }}">
                                {{ csrf_field() }}
                                <button type="submit" class="btn btn-default btn-sm">{{ __('updateguard::ug.sec_upload_and_scan') }}</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
                @if ($unknownVt->count() > 1)
                    <form method="POST" action="{{ route('updateguard.scan_all') }}" class="ug-inline" data-ug-confirm="{{ __('updateguard::ug.sec_upload_text') }} {{ __('updateguard::ug.vt_upload_all_quota') }}">
                        {{ csrf_field() }}
                        <input type="hidden" name="upload" value="1">
                        <button type="submit" class="btn btn-primary btn-sm">{{ __('updateguard::ug.vt_upload_all') }}</button>
                    </form>
                @endif
            </div>
        </div>
    @endif

    @foreach ($running as $job)
        @include('updateguard::partials.progress', ['jobId' => $job->id])
    @endforeach

    @include('updateguard::partials.modules_table', ['modules' => $modules, 'backups' => null])
@endsection
