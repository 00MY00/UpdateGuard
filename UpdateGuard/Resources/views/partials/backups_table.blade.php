<table class="table table-striped ug-table ug-cards">
    <thead>
        <tr>
            <th>{{ __('updateguard::ug.module') }}</th>
            <th>{{ __('updateguard::ug.version') }}</th>
            <th>{{ __('updateguard::ug.date') }}</th>
            <th>{{ __('updateguard::ug.size') }}</th>
            <th>{{ __('updateguard::ug.health') }}</th>
            <th>{{ __('updateguard::ug.backup_reason') }}</th>
            <th>{{ __('updateguard::ug.known_good') }}</th>
            <th class="ug-actions-col">{{ __('updateguard::ug.action') }}</th>
        </tr>
    </thead>
    <tbody>
    @forelse ($backups as $b)
        <tr>
            <td class="ug-card-title">{{ $b->module }}</td>
            <td data-label="{{ __('updateguard::ug.version') }}">{{ $b->version }}
                @if (isset($installed[$b->module]) && $installed[$b->module] !== '')<small class="text-help">({{ __('updateguard::ug.current') }} {{ $installed[$b->module] }})</small>@endif</td>
            <td data-label="{{ __('updateguard::ug.date') }}">{{ $b->created_at }}</td>
            <td data-label="{{ __('updateguard::ug.size') }}">{{ \Modules\UpdateGuard\Services\Ui::size($b->size) }}</td>
            <td data-label="{{ __('updateguard::ug.health') }}">{!! \Modules\UpdateGuard\Services\Ui::badge($b->health_status) !!}</td>
            <td data-label="{{ __('updateguard::ug.backup_reason') }}">{{ __('updateguard::ug.reason_'.$b->reason) }}</td>
            <td data-label="{{ __('updateguard::ug.known_good') }}">{{ $b->known_good ? '✓ '.__('updateguard::ug.yes') : '—' }}</td>
            <td class="ug-actions">
                @if ($b->module !== \Modules\UpdateGuard\Services\ModuleDiscoveryService::SELF)
                    <a class="btn btn-primary btn-sm" href="{{ route('updateguard.restore.confirm', ['id' => $b->id]) }}">↺ {{ __('updateguard::ug.restore') }}</a>
                @endif
                <div class="dropdown ug-inline">
                    <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown" aria-label="{{ __('updateguard::ug.more') }}">⋮</button>
                    <ul class="dropdown-menu dropdown-menu-right">
                        <li><a href="{{ route('updateguard.restore.download', ['id' => $b->id]) }}">{{ __('updateguard::ug.download') }}</a></li>
                        @if ($b->update_id !== '')
                            <li><a href="{{ route('updateguard.report', ['updateId' => $b->update_id]) }}">{{ __('updateguard::ug.view_report') }}</a></li>
                        @endif
                        <li><a href="#" class="text-danger" data-ug-post="{{ route('updateguard.restore.delete', ['id' => $b->id]) }}" data-ug-confirm="{{ __('updateguard::ug.confirm_delete_backup', ['module' => $b->module, 'version' => $b->version]) }}">{{ __('updateguard::ug.delete') }}</a></li>
                    </ul>
                </div>
            </td>
        </tr>
    @empty
        <tr><td colspan="8" class="text-help">{{ __('updateguard::ug.no_backup') }}</td></tr>
    @endforelse
    </tbody>
</table>
<form method="POST" id="ug-post-form" class="hidden">{{ csrf_field() }}</form>
