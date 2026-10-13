@extends('updateguard::layout')

@section('ug_content')
    <form method="GET" action="{{ route('updateguard.logs') }}" class="ug-filters">
        <select name="module" class="form-control input-sm" aria-label="{{ __('updateguard::ug.module') }}">
            <option value="">{{ __('updateguard::ug.all_modules') }}</option>
            @foreach ($modules as $name)
                <option value="{{ $name }}" @if ($f['module'] === $name) selected @endif>{{ $name }}</option>
            @endforeach
        </select>
        <input type="date" name="date" value="{{ $f['date'] }}" class="form-control input-sm" aria-label="{{ __('updateguard::ug.date') }}">
        <select name="severity" class="form-control input-sm" aria-label="{{ __('updateguard::ug.severity') }}">
            <option value="">{{ __('updateguard::ug.severity') }}</option>
            @foreach (\Modules\UpdateGuard\Services\Journal::LEVELS as $level)
                <option value="{{ $level }}" @if ($f['severity'] === $level) selected @endif>{{ $level }}</option>
            @endforeach
        </select>
        <select name="status" class="form-control input-sm" aria-label="{{ __('updateguard::ug.status') }}">
            <option value="">{{ __('updateguard::ug.status') }}</option>
            @foreach (array_merge(\Modules\UpdateGuard\Entities\UpdateJob::FINISHED, ['observing', 'queued']) as $status)
                <option value="{{ $status }}" @if ($f['status'] === $status) selected @endif>{{ __('updateguard::ug.job_'.$status) }}</option>
            @endforeach
        </select>
        <select name="trigger" class="form-control input-sm" aria-label="{{ __('updateguard::ug.triggered_by') }}">
            <option value="">{{ __('updateguard::ug.automatic') }} / {{ __('updateguard::ug.manual') }}</option>
            <option value="automatic" @if ($f['trigger'] === 'automatic') selected @endif>{{ __('updateguard::ug.automatic') }}</option>
            <option value="manual" @if ($f['trigger'] === 'manual') selected @endif>{{ __('updateguard::ug.manual') }}</option>
        </select>
        <input type="text" name="update_id" value="{{ $f['update_id'] }}" placeholder="UG-20261013-00042" class="form-control input-sm" aria-label="{{ __('updateguard::ug.update_id') }}">
        <button type="submit" class="btn btn-default btn-sm">{{ __('updateguard::ug.filter') }}</button>
    </form>

    <h3>{{ __('updateguard::ug.reports') }}</h3>
    @include('updateguard::partials.jobs_table', ['jobs' => $jobs])
    {{ $jobs->links() }}

    <h3>{{ __('updateguard::ug.logs') }}</h3>
    <div class="ug-log">
        @forelse ($logs as $log)
            <div class="ug-log-line ug-level-{{ strtolower($log->level) }}">
                <span class="ug-log-time">{{ $log->created_at }}</span>
                <span class="ug-log-level">{{ $log->level }}</span>
                <span class="ug-log-module">{{ $log->module ?: '—' }}</span>
                <span class="ug-log-message">{{ $log->message }}</span>
                @if ($log->update_id || $log->context)
                    <details class="ug-log-details">
                        <summary>{{ __('updateguard::ug.details') }}</summary>
                        @if ($log->update_id)<div><a href="{{ route('updateguard.report', ['updateId' => $log->update_id]) }}">{{ $log->update_id }}</a> · {{ $log->step }} · {{ $log->from_version }}@if ($log->to_version) → {{ $log->to_version }}@endif</div>@endif
                        @if ($log->context)<pre>{{ $log->context }}</pre>@endif
                    </details>
                @endif
            </div>
        @empty
            <p class="text-help">{{ __('updateguard::ug.no_log') }}</p>
        @endforelse
    </div>
    {{ $logs->links() }}
@endsection
