@extends('updateguard::layout')

@section('ug_content')
    @php
        $caps = $module->capabilities();
        $Ui = \Modules\UpdateGuard\Services\Ui::class;
        $knownGood = $backups->first(function ($b) use ($module) { return $b->known_good && $b->version !== $module->installed_version; });
        $pass = function ($ok, $na = false) { return $ok ? 'PASS' : ($na ? 'NOT AVAILABLE' : 'FAIL'); };
    @endphp

    <h2 class="ug-title">{{ $module->name }} <small>{{ $module->installed_version }}</small> {!! $Ui::badge($Ui::moduleState($module)) !!}</h2>

    @if ($self)
        <div class="alert alert-info">{{ __('updateguard::ug.self_update') }}</div>
    @elseif ($module->safety_level !== 'unsupported' && $module->auto_update)
        <div class="alert alert-info">{{ __('updateguard::ug.managed_by') }}</div>
    @endif
    @if ($module->state === 'critical')
        <div class="alert alert-danger">× {{ __('updateguard::ug.critical_help') }} {{ $module->last_error }}</div>
    @endif
    @if ($module->blocked_version !== '')
        <div class="alert alert-warning">
            × {{ $module->name }} {{ $module->blocked_version }} — {{ __('updateguard::ug.state_blocked') }}<br>
            {{ __('updateguard::ug.reason') }}: {{ $module->blocked_reason }}<br>
            {{ __('updateguard::ug.known_good') }}: {{ $module->known_good_version ?: '—' }}
            <form method="POST" action="{{ route('updateguard.module.unblock', ['name' => $module->name]) }}" class="ug-inline" data-ug-confirm="{{ __('updateguard::ug.confirm_unblock', ['version' => $module->blocked_version]) }}">
                {{ csrf_field() }}
                <button type="submit" class="btn btn-default btn-xs">{{ __('updateguard::ug.unblock') }}</button>
            </form>
        </div>
    @endif
    @if ($pending)
        @include('updateguard::partials.progress', ['jobId' => $pending->id])
    @endif

    @unless ($self)
    <div class="ug-toolbar">
        @if ($module->safety_level !== 'unsupported')
            <form method="POST" action="{{ route('updateguard.module.update', ['name' => $module->name]) }}" class="ug-inline" data-ug-confirm="{{ __('updateguard::ug.confirm_update', ['module' => $module->name, 'from' => $module->installed_version, 'to' => $module->latest_version ?: '?']) }}">
                {{ csrf_field() }}
                <button type="submit" class="btn btn-primary" @if (!$module->updateAvailable() && $module->blocked_version === '') disabled @endif>↑ {{ __('updateguard::ug.update') }} {{ $module->latest_version }}</button>
                @if ($module->blocked_version !== '' && $module->blocked_version === $module->latest_version)
                    <label class="ug-force"><input type="checkbox" name="force" value="1"> {{ __('updateguard::ug.force_blocked') }}</label>
                @endif
            </form>
        @endif
        <form method="POST" action="{{ route('updateguard.module.check', ['name' => $module->name]) }}" class="ug-inline">
            {{ csrf_field() }}
            <button type="submit" class="btn btn-default">{{ __('updateguard::ug.check') }}</button>
        </form>
        <form method="POST" action="{{ route('updateguard.module.health', ['name' => $module->name]) }}" class="ug-inline">
            {{ csrf_field() }}
            <button type="submit" class="btn btn-default">{{ __('updateguard::ug.health_check') }}</button>
        </form>
        @if ($knownGood)
            <a class="btn btn-default" href="{{ route('updateguard.restore.confirm', ['id' => $knownGood->id]) }}">↺ {{ __('updateguard::ug.rollback_known_good', ['version' => $knownGood->version]) }}</a>
        @endif
    </div>
    @endunless

    <div class="row">
        <div class="col-md-6">
            <h3>{{ __('updateguard::ug.compatibility_report') }}</h3>
            <table class="table table-condensed ug-kv">
                <tr><th>{{ __('updateguard::ug.version_discovery') }}</th><td>{{ $pass($module->latest_version !== '', empty($caps['version_url'])) }} @if (!empty($caps['error']))<br><small class="text-danger">{{ $caps['error'] }}</small>@endif</td></tr>
                <tr><th>{{ __('updateguard::ug.zip_update') }}</th><td>{{ !empty($caps['zip_url']) ? 'PASS' : 'NOT AVAILABLE' }}</td></tr>
                <tr><th>SHA-256</th><td>{{ !empty($caps['sha256']) ? 'PASS' : 'NOT AVAILABLE' }}</td></tr>
                <tr><th>{{ __('updateguard::ug.native_health') }}</th><td>{{ !empty($caps['native_health']) ? 'AVAILABLE' : 'NOT AVAILABLE' }} <small class="text-help">{{ $caps['health_command'] ?? '' }}</small></td></tr>
                <tr><th>{{ __('updateguard::ug.fallback_validation') }}</th><td>AVAILABLE</td></tr>
                <tr><th>{{ __('updateguard::ug.rollback') }}</th><td>{{ !empty($caps['has_migrations']) ? 'FILES ONLY' : 'FILE-SAFE' }}</td></tr>
                <tr><th>{{ __('updateguard::ug.safety') }}</th><td>{!! $Ui::level($module->safety_level) !!}</td></tr>
            </table>
        </div>
        <div class="col-md-6">
            <h3>{{ __('updateguard::ug.details') }}</h3>
            <table class="table table-condensed ug-kv">
                <tr><th>{{ __('updateguard::ug.installed') }}</th><td>{{ $module->installed_version }}</td></tr>
                <tr><th>{{ __('updateguard::ug.latest') }}</th><td>{{ $module->latest_version ?: '—' }}</td></tr>
                <tr><th>{{ __('updateguard::ug.known_good') }}</th><td>{{ $module->known_good_version ?: '—' }}</td></tr>
                <tr><th>{{ __('updateguard::ug.current_state') }}</th><td>{!! $Ui::badge($module->state) !!}</td></tr>
                <tr><th>{{ __('updateguard::ug.health') }}</th><td>{!! $Ui::badge($module->health_status) !!} <small class="text-help">{{ $module->health_checked_at }}</small></td></tr>
                <tr><th>{{ __('updateguard::ug.last_check') }}</th><td>{{ $module->last_check_at ?: '—' }}</td></tr>
                <tr><th>{{ __('updateguard::ug.last_update') }}</th><td>{{ $module->last_update_at ?: '—' }}</td></tr>
                <tr><th>{{ __('updateguard::ug.last_backup') }}</th><td>{{ $module->last_backup_at ?: '—' }}</td></tr>
                @unless ($self)
                <tr><th>{{ __('updateguard::ug.auto_update') }}</th><td>
                    <form method="POST" action="{{ route('updateguard.module.auto', ['name' => $module->name]) }}" class="ug-inline">
                        {{ csrf_field() }}
                        <input type="hidden" name="auto_update" value="{{ $module->auto_update ? 0 : 1 }}">
                        {{ $module->auto_update ? __('updateguard::ug.allowed') : __('updateguard::ug.not_allowed') }}
                        <button type="submit" class="btn btn-link btn-xs">{{ $module->auto_update ? __('updateguard::ug.disallow') : __('updateguard::ug.allow') }}</button>
                    </form>
                    <small class="text-help">{{ __('updateguard::ug.auto_update_hint') }}</small>
                </td></tr>
                @endunless
            </table>
        </div>
    </div>

    <h3>{{ __('updateguard::ug.backups') }}</h3>
    @include('updateguard::partials.backups_table', ['backups' => $backups, 'installed' => [$module->name => $module->installed_version]])

    <h3>{{ __('updateguard::ug.history') }}</h3>
    @include('updateguard::partials.jobs_table', ['jobs' => $jobs])
@endsection
