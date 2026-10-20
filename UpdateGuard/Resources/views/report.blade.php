@extends('updateguard::layout')

@section('ug_content')
    <h2 class="ug-title">{{ $updateId }}
        @if ($job) {!! \Modules\UpdateGuard\Services\Ui::badge($job->status) !!} @endif
    </h2>
    @if ($job && !$job->isFinished())
        @include('updateguard::partials.progress', ['jobId' => $job->id])
    @endif
    @if ($report)
        <div class="ug-toolbar">
            <a class="btn btn-default btn-sm" href="{{ route('updateguard.report.download', ['updateId' => $updateId, 'format' => 'txt']) }}">TXT</a>
            <a class="btn btn-default btn-sm" href="{{ route('updateguard.report.download', ['updateId' => $updateId, 'format' => 'json']) }}">JSON</a>
            <a class="btn btn-default btn-sm" href="{{ route('updateguard.report.download', ['updateId' => $updateId, 'format' => 'html']) }}">HTML</a>
            @if (!$job || $job->isFinished())
                <form method="POST" action="{{ route('updateguard.report.delete', ['updateId' => $updateId]) }}" class="ug-inline" data-ug-confirm="{{ __('updateguard::ug.confirm_delete_report') }}">
                    {{ csrf_field() }}
                    <button type="submit" class="btn btn-link btn-sm text-danger">{{ __('updateguard::ug.delete') }}</button>
                </form>
            @endif
        </div>
        @if (!empty($report['compatibility']['platform']))
            <h3>{{ __('updateguard::ug.compat_checks') }}</h3>
            @include('updateguard::partials.compatibility', ['c' => $report['compatibility']])
            @php $cd = $report['compatibility']['decision'] ?? []; @endphp
            @if ($job && $job->status === 'blocked' && ($cd['decision'] ?? '') === 'blocked' && !empty($cd['overridable'])
                && !\Modules\UpdateGuard\Entities\UpdateJob::pendingFor($job->module))
                {{-- blocked by the compatibility: the administrator may install anyway (logged as CRITICAL) --}}
                <form method="POST" action="{{ route('updateguard.module.update', ['name' => $job->module]) }}" class="ug-override"
                      data-ug-confirm="{{ __('updateguard::ug.compat_override_confirm') }}">
                    {{ csrf_field() }}
                    <input type="hidden" name="compat_override" value="1">
                    <label><input type="checkbox" name="understand" value="1" required> {{ __('updateguard::ug.compat_override_understand') }}</label>
                    <button type="submit" class="btn btn-danger btn-sm">{{ __('updateguard::ug.compat_install_anyway') }}</button>
                </form>
            @endif
        @endif
        <pre class="ug-report">{{ \Modules\UpdateGuard\Services\ReportService::toText($report) }}</pre>
        @if (!empty($report['native_health']['json']))
            <details>
                <summary>{{ __('updateguard::ug.native_health') }} (JSON)</summary>
                <pre>{{ json_encode($report['native_health']['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            </details>
        @endif
    @else
        <p class="text-help">{{ __('updateguard::ug.no_report') }}</p>
        @foreach ($logs as $log)
            <div>{{ $log->created_at }} {{ $log->level }} {{ $log->message }}</div>
        @endforeach
    @endif
@endsection
