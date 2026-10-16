{{-- State of every security test of a report and its findings (trusted ones marked). $report = SecurityReport::data(). --}}
@php
    $Ui = \Modules\UpdateGuard\Services\Ui::class;
    $labels = ['integrity', 'structure', 'php', 'obfuscation', 'executables', 'filesystem', 'network', 'installer'];
    $findings = (array) ($report['findings'] ?? []);
@endphp
<table class="table table-condensed ug-kv ug-sec-checks">
    @foreach ($labels as $check)
        @php $c = $report['checks'][$check] ?? ['status' => 'DISABLED']; @endphp
        <tr>
            <th>{{ __('updateguard::ug.set_sec_check_'.$check) }}</th>
            <td>{!! $Ui::secStatus($c['status']) !!}
                @if (!empty($c['count'])) <small class="text-help">{{ __('updateguard::ug.sec_findings_count', ['count' => $c['count']]) }}</small>@endif
                @if (!empty($c['trusted'])) <small class="ug-trusted-note">✓ {{ __('updateguard::ug.trust_count', ['count' => $c['trusted']]) }}</small>@endif</td>
        </tr>
    @endforeach
    @foreach ((array) ($report['external'] ?? []) as $name => $e)
        <tr><th>{{ $name }}</th><td>{!! $Ui::secStatus($e['status']) !!} <small class="text-help">{{ $e['message'] ?? '' }}</small></td></tr>
    @endforeach
</table>
@if ($findings)
    <details class="ug-findings" @if (!empty($open)) open @endif>
        <summary>{{ __('updateguard::ug.sec_findings') }} ({{ count($findings) }})</summary>
        <div class="ug-log">
            @foreach ($findings as $f)
                <div class="ug-log-line ug-level-{{ !empty($f['trusted']) ? 'debug' : ($f['severity'] === 'CRITICAL' || $f['severity'] === 'HIGH' ? 'error' : ($f['severity'] === 'MEDIUM' ? 'warning' : 'info')) }}">
                    <span class="ug-log-level">[{{ $f['severity'] }}]</span>
                    <span class="ug-log-message">{{ $f['message'] }}@if (!empty($f['trusted'])) <span class="ug-badge ug-ok">✓ {{ __('updateguard::ug.trust_trusted') }}</span>@endif</span>
                    @if ($f['file'] !== '')<span class="ug-log-module">{{ $f['file'] }}@if ($f['line']):{{ $f['line'] }}@endif</span>@endif
                </div>
            @endforeach
        </div>
    </details>
@endif
