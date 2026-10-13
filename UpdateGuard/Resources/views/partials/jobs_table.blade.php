<table class="table table-striped ug-table ug-cards">
    <thead>
        <tr>
            <th>{{ __('updateguard::ug.update_id') }}</th>
            <th>{{ __('updateguard::ug.module') }}</th>
            <th>{{ __('updateguard::ug.operation') }}</th>
            <th>{{ __('updateguard::ug.versions') }}</th>
            <th>{{ __('updateguard::ug.status') }}</th>
            <th>{{ __('updateguard::ug.triggered_by') }}</th>
            <th>{{ __('updateguard::ug.date') }}</th>
        </tr>
    </thead>
    <tbody>
    @forelse ($jobs as $job)
        <tr>
            <td class="ug-card-title"><a href="{{ route('updateguard.report', ['updateId' => $job->update_id]) }}">{{ $job->update_id }}</a></td>
            <td data-label="{{ __('updateguard::ug.module') }}">{{ $job->module === '*' ? __('updateguard::ug.all_modules') : $job->module }}</td>
            <td data-label="{{ __('updateguard::ug.operation') }}">{{ __('updateguard::ug.type_'.$job->type) }}</td>
            <td data-label="{{ __('updateguard::ug.versions') }}">{{ $job->from_version }}@if ($job->to_version !== '' && $job->to_version !== $job->from_version) → {{ $job->to_version }}@endif</td>
            <td data-label="{{ __('updateguard::ug.status') }}">{!! \Modules\UpdateGuard\Services\Ui::badge($job->status) !!}
                @if ($job->message)<br><small class="text-help">{{ \Illuminate\Support\Str::limit($job->message, 160) }}</small>@endif</td>
            <td data-label="{{ __('updateguard::ug.triggered_by') }}">{{ $job->triggered_by }} · {{ $job->automatic ? __('updateguard::ug.automatic') : __('updateguard::ug.manual') }}</td>
            <td data-label="{{ __('updateguard::ug.date') }}">{{ $job->created_at }}</td>
        </tr>
    @empty
        <tr><td colspan="7" class="text-help">{{ __('updateguard::ug.no_operation') }}</td></tr>
    @endforelse
    </tbody>
</table>
