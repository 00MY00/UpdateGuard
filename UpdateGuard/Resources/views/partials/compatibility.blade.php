{{-- Project compatibility: $c = Compatibility\CompatibilityChecker::evaluate() (+ 'decision' when decided) --}}
@php
    $Ui = \Modules\UpdateGuard\Services\Ui::class;
    $platformRows = array_filter($c['platform'], function ($p) { return $p['result'] !== 'NOT DECLARED'; });
@endphp
<p class="text-help">
    @if ($c['source'] !== '')
        {{ __('updateguard::ug.compat_source', ['source' => $c['source']]) }}
    @else
        {{ __('updateguard::ug.compat_no_metadata') }}
    @endif
</p>
@if ($platformRows || $c['projects'])
    <table class="table table-striped ug-table ug-cards ug-compat">
        <thead>
            <tr>
                <th>{{ __('updateguard::ug.compat_project') }}</th>
                <th>{{ __('updateguard::ug.compat_relationship') }}</th>
                <th>{{ __('updateguard::ug.installed') }}</th>
                <th>{{ __('updateguard::ug.compat_supported') }}</th>
                <th>{{ __('updateguard::ug.compat_interop') }}</th>
                <th>{{ __('updateguard::ug.status') }}</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($platformRows as $p)
            <tr>
                <td class="ug-card-title">{{ $p['name'] }}</td>
                <td data-label="{{ __('updateguard::ug.compat_relationship') }}">{{ __('updateguard::ug.compat_platform') }}</td>
                <td data-label="{{ __('updateguard::ug.installed') }}">{{ $p['installed'] ?: '—' }}</td>
                <td data-label="{{ __('updateguard::ug.compat_supported') }}">{{ $p['required'] }}</td>
                <td data-label="{{ __('updateguard::ug.compat_interop') }}">—</td>
                <td data-label="{{ __('updateguard::ug.status') }}">{!! $Ui::compat($p['result']) !!}</td>
            </tr>
        @endforeach
        @foreach ($c['projects'] as $p)
            <tr>
                <td class="ug-card-title">
                    @if ($p['repository'] !== '')
                        <a href="{{ $p['repository'] }}" target="_blank" rel="noopener noreferrer">{{ $p['name'] }}</a>
                    @else
                        {{ $p['name'] }}
                    @endif
                    <br><small class="text-help">{{ $p['id'] }}</small>
                </td>
                <td data-label="{{ __('updateguard::ug.compat_relationship') }}">{{ __('updateguard::ug.compat_rel_'.$p['relationship']) }}
                    <br><small class="text-help">{{ $p['required'] ? __('updateguard::ug.compat_required') : __('updateguard::ug.compat_optional') }}</small></td>
                <td data-label="{{ __('updateguard::ug.installed') }}">{{ $p['installed'] ? $p['version'] : '—' }}@if ($p['installed'] && !$p['active']) <small>({{ __('updateguard::ug.disabled') }})</small>@endif</td>
                <td data-label="{{ __('updateguard::ug.compat_supported') }}">{{ $p['supported_versions'] ?: '—' }}
                    @if ($p['legacy_versions'] !== '')<br><small>{{ __('updateguard::ug.compat_legacy') }} : {{ $p['legacy_versions'] }}</small>@endif
                    @if ($p['conflicts'])<br><small>{{ __('updateguard::ug.compat_conflicts') }} : {{ implode(', ', $p['conflicts']) }}</small>@endif</td>
                <td data-label="{{ __('updateguard::ug.compat_interop') }}">{{ $p['interop'] !== '' ? $p['interop'] : '—' }}@if ($p['interop_installed'] !== '') <small>({{ $p['interop_installed'] }})</small>@endif</td>
                <td data-label="{{ __('updateguard::ug.status') }}">{!! $Ui::compat($p['state'], $p['relationship']) !!}
                    @if ($p['reason'] !== '')<br><small class="text-help">{{ $p['reason'] }}</small>@endif</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif
@foreach ((array) ($c['errors'] ?? []) as $error)
    <div class="text-warning"><small>! {{ __('updateguard::ug.compat_metadata_error') }} : {{ $error }}</small></div>
@endforeach
<p class="ug-compat-overall">
    <strong>{{ __('updateguard::ug.compat_overall') }}</strong> {!! $Ui::compat($c['overall']) !!}
    @if (!empty($c['decision']))
        · <strong>{{ __('updateguard::ug.compat_result') }}</strong>
        <span class="ug-badge ug-{{ $c['decision']['decision'] === 'blocked' ? 'fail' : (in_array($c['decision']['code'], ['safe'], true) ? 'ok' : 'warn') }}">{{ __('updateguard::ug.compat_label_'.$c['decision']['code']) }}</span>
    @endif
</p>
