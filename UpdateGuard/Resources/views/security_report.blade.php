@extends('updateguard::layout')

@php
    $Ui = \Modules\UpdateGuard\Services\Ui::class;
    $labels = [
        'integrity'   => 'sec_check_integrity',
        'structure'   => 'sec_check_structure',
        'php'         => 'sec_check_php',
        'obfuscation' => 'sec_check_obfuscation',
        'executables' => 'sec_check_executables',
        'filesystem'  => 'sec_check_filesystem',
        'network'     => 'sec_check_network',
        'installer'   => 'sec_check_installer',
    ];
    $decision = $report['decision'] ?? '';
@endphp

@section('ug_content')
    <h2 class="ug-title">{{ __('updateguard::ug.sec_verification') }} — {{ $row->module }}
        <small>{{ $row->from_version }}@if ($row->to_version !== '' && $row->to_version !== $row->from_version) → {{ $row->to_version }}@endif</small></h2>

    <div class="panel panel-default ug-sec-summary">
        <div class="panel-body">
            <table class="table table-condensed ug-kv">
                @foreach ($labels as $check => $key)
                    <tr>
                        <th>{{ __('updateguard::ug.set_'.$key) }}</th>
                        <td>{!! $Ui::secStatus($report['checks'][$check]['status'] ?? 'DISABLED') !!}
                            @if (!empty($report['checks'][$check]['count'])) <small class="text-help">{{ __('updateguard::ug.sec_findings_count', ['count' => $report['checks'][$check]['count']]) }}</small>@endif</td>
                    </tr>
                @endforeach
                @forelse ($report['external'] ?? [] as $name => $e)
                    <tr>
                        <th>{{ $name }}</th>
                        <td>{!! $Ui::secStatus($e['status']) !!} <small class="text-help">{{ $e['message'] }}@if (!empty($e['cached'])) · {{ __('updateguard::ug.sec_cached') }}@endif</small>
                            @if (!empty($e['stats']))
                                <div class="ug-vt-stats">
                                    {{ __('updateguard::ug.vt_malicious') }}: {{ $e['stats']['malicious'] }} ·
                                    {{ __('updateguard::ug.vt_suspicious') }}: {{ $e['stats']['suspicious'] }} ·
                                    {{ __('updateguard::ug.vt_harmless') }}: {{ $e['stats']['harmless'] }} ·
                                    {{ __('updateguard::ug.vt_undetected') }}: {{ $e['stats']['undetected'] }}
                                    @if (!empty($e['link'])) · <a href="{{ $e['link'] }}" target="_blank" rel="noopener noreferrer">{{ __('updateguard::ug.view_report') }}</a>@endif
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><th>{{ __('updateguard::ug.sec_external_scan') }}</th><td>{!! $Ui::secStatus('DISABLED') !!}</td></tr>
                @endforelse
                <tr><th>{{ __('updateguard::ug.sec_risk') }}</th><td>{!! $Ui::risk($report['score'] ?? 0, $report['level'] ?? 'LOW') !!}</td></tr>
                <tr><th>SHA-256</th><td><code class="ug-hash">{{ $row->sha256 ?: '—' }}</code></td></tr>
                <tr><th>{{ __('updateguard::ug.sec_source') }}</th><td class="ug-break">{{ $row->source ?: '—' }}</td></tr>
                <tr><th>{{ __('updateguard::ug.sec_decision') }}</th><td>{{ __('updateguard::ug.sec_decision_'.$row->decision) }}
                    @if ($row->override_by !== '') — {{ __('updateguard::ug.sec_override_by', ['name' => $row->override_by]) }}@endif</td></tr>
                @if ($row->result !== '')
                    <tr><th>{{ __('updateguard::ug.sec_result') }}</th><td>{!! $Ui::badge($row->result) !!}
                        @if ($row->health !== '') · {{ __('updateguard::ug.health_check') }}: {{ $row->health }}@endif
                        @if ($row->rollback !== '') · {{ __('updateguard::ug.rollback') }}: {{ $row->rollback }}@endif</td></tr>
                @endif
            </table>

            @if ($decision === 'blocked' && empty($report['overridable']))
                <div class="alert alert-danger"><strong>× {{ __('updateguard::ug.sec_blocked') }}</strong><br>{{ __('updateguard::ug.sec_not_overridable') }}</div>
            @elseif ($decision === 'blocked')
                <div class="alert alert-danger"><strong>× {{ __('updateguard::ug.sec_blocked') }}</strong></div>
            @endif

            @if ($waiting)
                {{-- the update is paused before any change: the administrator decides --}}
                @if ($decision === 'upload_consent')
                    <div class="alert alert-warning">
                        <strong>{{ __('updateguard::ug.sec_upload_title') }}</strong><br>
                        {{ __('updateguard::ug.sec_upload_text') }}
                    </div>
                    <div class="ug-confirm-buttons">
                        <form method="POST" action="{{ route('updateguard.security.decide', ['id' => $row->id]) }}" class="ug-inline">
                            {{ csrf_field() }}<input type="hidden" name="action" value="cancel">
                            <button type="submit" class="btn btn-default">{{ __('updateguard::ug.cancel') }}</button>
                        </form>
                        <form method="POST" action="{{ route('updateguard.security.decide', ['id' => $row->id]) }}" class="ug-inline">
                            {{ csrf_field() }}<input type="hidden" name="action" value="skip_upload">
                            <button type="submit" class="btn btn-default">{{ __('updateguard::ug.sec_continue_without_upload') }}</button>
                        </form>
                        <form method="POST" action="{{ route('updateguard.security.decide', ['id' => $row->id]) }}" class="ug-inline">
                            {{ csrf_field() }}<input type="hidden" name="action" value="upload">
                            <button type="submit" class="btn btn-primary">{{ __('updateguard::ug.sec_upload_and_scan') }}</button>
                        </form>
                    </div>
                @elseif ($decision === 'confirm')
                    <div class="ug-confirm-buttons">
                        <form method="POST" action="{{ route('updateguard.security.decide', ['id' => $row->id]) }}" class="ug-inline">
                            {{ csrf_field() }}<input type="hidden" name="action" value="cancel">
                            <button type="submit" class="btn btn-default">{{ __('updateguard::ug.cancel') }}</button>
                        </form>
                        <form method="POST" action="{{ route('updateguard.security.decide', ['id' => $row->id]) }}" class="ug-inline">
                            {{ csrf_field() }}<input type="hidden" name="action" value="continue">
                            <button type="submit" class="btn btn-primary">{{ __('updateguard::ug.sec_continue_update') }}</button>
                        </form>
                    </div>
                @endif
            @elseif ($decision === 'blocked' && !empty($report['overridable']) && $job && $job->isWaiting() === false && $job->status === 'blocked' && $job->update_id === $row->update_id && \Modules\UpdateGuard\Services\Settings::get('sec_allow_override'))
                {{-- override: deliberate action, strong warning, logged --}}
                <div class="alert alert-danger ug-override">
                    <strong>{{ __('updateguard::ug.sec_override_title', ['level' => $row->level]) }}</strong><br>
                    {{ __('updateguard::ug.sec_override_text') }}
                    <form method="POST" action="{{ route('updateguard.module.update', ['name' => $row->module]) }}" class="ug-override-form"
                          data-ug-confirm="{{ __('updateguard::ug.sec_override_confirm', ['module' => $row->module, 'level' => $row->level]) }}">
                        {{ csrf_field() }}
                        <input type="hidden" name="override_report" value="{{ $row->id }}">
                        <label class="ug-force"><input type="checkbox" name="understand" value="1" required> {{ __('updateguard::ug.sec_override_understand') }}</label>
                        <div class="ug-confirm-buttons">
                            <a href="{{ route('updateguard.security') }}" class="btn btn-default">{{ __('updateguard::ug.cancel') }}</a>
                            <button type="submit" class="btn btn-danger">{{ __('updateguard::ug.sec_install_anyway') }}</button>
                        </div>
                    </form>
                </div>
            @endif
        </div>
    </div>

    <h3>{{ __('updateguard::ug.sec_findings') }}</h3>
    @if (empty($report['findings']))
        <p class="text-help">{{ __('updateguard::ug.sec_no_finding') }}</p>
    @else
        <div class="ug-log">
            @foreach ($report['findings'] as $f)
                <div class="ug-log-line ug-level-{{ $f['severity'] === 'CRITICAL' || $f['severity'] === 'HIGH' ? 'error' : ($f['severity'] === 'MEDIUM' ? 'warning' : 'info') }}">
                    <span class="ug-log-level">[{{ $f['severity'] }}]</span>
                    <span class="ug-log-message">{{ $f['message'] }}</span>
                    @if ($f['file'] !== '')<span class="ug-log-module">{{ $f['file'] }}@if ($f['line']):{{ $f['line'] }}@endif</span>@endif
                </div>
            @endforeach
        </div>
    @endif
    <p class="text-help">{{ __('updateguard::ug.sec_disclaimer') }}</p>
    <details>
        <summary>JSON</summary>
        <pre class="ug-report">{{ \Modules\UpdateGuard\Services\Security\SecurityGate::toJson($report) }}</pre>
    </details>
    @if ($row->update_id !== '')
        <p><a href="{{ route('updateguard.report', ['updateId' => $row->update_id]) }}">{{ __('updateguard::ug.view_report') }} ({{ $row->update_id }})</a></p>
    @endif
@endsection
