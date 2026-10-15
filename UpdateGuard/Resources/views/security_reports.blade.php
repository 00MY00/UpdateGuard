@extends('updateguard::layout')

@section('ug_content')
    @if (!\Modules\UpdateGuard\Services\Settings::get('security_gate'))
        <div class="alert alert-warning">! {{ __('updateguard::ug.sec_gate_off') }} <a href="{{ route('updateguard.settings') }}#ug-security">{{ __('updateguard::ug.settings') }}</a></div>
    @endif
    <div class="ug-toolbar">
        <form method="GET" action="{{ route('updateguard.security') }}" class="form-inline ug-filters">
            <select name="module" class="form-control input-sm" aria-label="{{ __('updateguard::ug.module') }}">
                <option value="">{{ __('updateguard::ug.all_modules') }}</option>
                @foreach ($modules as $name)
                    <option value="{{ $name }}" @if ($module === $name) selected @endif>{{ $name }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-default btn-sm">{{ __('updateguard::ug.filter') }}</button>
        </form>
        <form method="POST" action="{{ route('updateguard.security.cleanup') }}" class="ug-inline" data-ug-confirm="{{ __('updateguard::ug.sec_cleanup_confirm') }}">
            {{ csrf_field() }}
            <button type="submit" class="btn btn-default btn-sm">{{ __('updateguard::ug.sec_cleanup') }}</button>
        </form>
    </div>
    <p class="text-help">{{ __('updateguard::ug.sec_disclaimer') }}</p>

    <table class="table table-striped ug-table ug-cards">
        <thead>
            <tr>
                <th>{{ __('updateguard::ug.date') }}</th>
                <th>{{ __('updateguard::ug.module') }}</th>
                <th>{{ __('updateguard::ug.version') }}</th>
                <th>{{ __('updateguard::ug.sec_integrity') }}</th>
                <th>{{ __('updateguard::ug.sec_local_scan') }}</th>
                <th>{{ __('updateguard::ug.sec_external_scan') }}</th>
                <th>{{ __('updateguard::ug.sec_risk') }}</th>
                <th>{{ __('updateguard::ug.sec_decision') }}</th>
                <th>{{ __('updateguard::ug.sec_result') }}</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($reports as $r)
            <tr>
                <td class="ug-card-title"><a href="{{ route('updateguard.security.show', ['id' => $r->id]) }}">{{ $r->created_at }}</a></td>
                <td data-label="{{ __('updateguard::ug.module') }}">{{ $r->module }}</td>
                <td data-label="{{ __('updateguard::ug.version') }}">{{ $r->from_version }}@if ($r->to_version !== '' && $r->to_version !== $r->from_version) → {{ $r->to_version }}@endif</td>
                <td data-label="{{ __('updateguard::ug.sec_integrity') }}">{!! \Modules\UpdateGuard\Services\Ui::secStatus($r->integrity) !!}</td>
                <td data-label="{{ __('updateguard::ug.sec_local_scan') }}">{!! \Modules\UpdateGuard\Services\Ui::secStatus($r->local_status) !!}</td>
                <td data-label="{{ __('updateguard::ug.sec_external_scan') }}">{!! \Modules\UpdateGuard\Services\Ui::secStatus($r->external_status) !!}</td>
                <td data-label="{{ __('updateguard::ug.sec_risk') }}">{!! \Modules\UpdateGuard\Services\Ui::risk($r->score, $r->level) !!}</td>
                <td data-label="{{ __('updateguard::ug.sec_decision') }}">{{ __('updateguard::ug.sec_decision_'.$r->decision) }}@if ($r->override_by !== '')<br><small>{{ $r->override_by }}</small>@endif</td>
                <td data-label="{{ __('updateguard::ug.sec_result') }}">{!! $r->result !== '' ? \Modules\UpdateGuard\Services\Ui::badge($r->result) : '—' !!}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="text-help">{{ __('updateguard::ug.sec_no_report') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $reports->links() }}
@endsection
