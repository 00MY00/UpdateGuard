{{-- Table on desktop, cards on phones (updateguard.css: .ug-cards). Secondary actions in the "⋮" menu. --}}
<table class="table table-striped ug-table ug-cards">
    <thead>
        <tr>
            <th>{{ __('updateguard::ug.module') }}</th>
            <th>{{ __('updateguard::ug.installed') }}</th>
            <th>{{ __('updateguard::ug.latest') }}</th>
            <th>{{ __('updateguard::ug.health') }}</th>
            <th>{{ __('updateguard::ug.safety') }}</th>
            @if ($backups !== null)
                <th>{{ __('updateguard::ug.last_backup') }}</th>
            @endif
            <th class="ug-actions-col">{{ __('updateguard::ug.action') }}</th>
        </tr>
    </thead>
    <tbody>
    @foreach ($modules as $m)
        @php $self = $m->name === \Modules\UpdateGuard\Services\ModuleDiscoveryService::SELF; @endphp
        <tr>
            <td class="ug-card-title"><a href="{{ route('updateguard.module', ['name' => $m->name]) }}">{{ $m->name }}</a>
                @if (!$m->enabled) <small class="text-help">({{ __('updateguard::ug.disabled') }})</small> @endif
                @if ($m->blocked_version !== '') <br><small>× {{ __('updateguard::ug.blocked_version', ['version' => $m->blocked_version]) }}</small> @endif
            </td>
            <td data-label="{{ __('updateguard::ug.installed') }}">{{ $m->installed_version ?: '—' }}</td>
            <td data-label="{{ __('updateguard::ug.latest') }}">{{ $m->latest_version ?: '—' }}
                @php $why = \Modules\UpdateGuard\Services\Ui::latestUnknownReason($m); @endphp
                @if ($why !== '')<br><small class="text-help ug-latest-why">{{ $why }}</small>@endif</td>
            @if ($self)
                {{-- UpdateGuard checks its own files against its release (+ VirusTotal) instead of updating itself --}}
                <td data-label="{{ __('updateguard::ug.health') }}">{!! \Modules\UpdateGuard\Services\Ui::selfBadge($m) !!}</td>
                <td data-label="{{ __('updateguard::ug.safety') }}">{!! \Modules\UpdateGuard\Services\Ui::selfLevel() !!}</td>
            @else
                <td data-label="{{ __('updateguard::ug.health') }}">{!! \Modules\UpdateGuard\Services\Ui::badge(\Modules\UpdateGuard\Services\Ui::moduleState($m)) !!}</td>
                <td data-label="{{ __('updateguard::ug.safety') }}">{!! \Modules\UpdateGuard\Services\Ui::level($m->safety_level) !!}</td>
            @endif
            @if ($backups !== null)
                <td data-label="{{ __('updateguard::ug.last_backup') }}">{{ $backups[$m->name] ?? '—' }}</td>
            @endif
            <td class="ug-actions">
                @if ($self)
                    @if ($m->updateAvailable())
                        <a href="{{ \Modules\UpdateGuard\Services\Ui::freescoutModulesUrl() }}" class="btn btn-primary btn-sm" title="{{ __('updateguard::ug.self_update') }}">↑ {{ __('updateguard::ug.self_update_button') }}</a>
                    @endif
                @elseif ($m->updateAvailable() && $m->safety_level !== 'unsupported' && $m->blocked_version !== $m->latest_version)
                    <form method="POST" action="{{ route('updateguard.module.update', ['name' => $m->name]) }}" class="ug-inline" data-ug-confirm="{{ __('updateguard::ug.confirm_update', ['module' => $m->name, 'from' => $m->installed_version, 'to' => $m->latest_version]) }}">
                        {{ csrf_field() }}
                        <button type="submit" class="btn btn-primary btn-sm">↑ {{ __('updateguard::ug.update') }}</button>
                    </form>
                @endif
                <div class="dropdown ug-inline">
                    <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown" aria-label="{{ __('updateguard::ug.more') }}">⋮</button>
                    <ul class="dropdown-menu dropdown-menu-right">
                        <li><a href="{{ route('updateguard.module', ['name' => $m->name]) }}">{{ __('updateguard::ug.details') }}</a></li>
                        <li><a href="#" data-ug-post="{{ route('updateguard.module.check', ['name' => $m->name]) }}">{{ __('updateguard::ug.check') }}</a></li>
                        <li><a href="#" data-ug-post="{{ route('updateguard.module.health', ['name' => $m->name]) }}">{{ __('updateguard::ug.health_check') }}</a></li>
                        <li><a href="{{ route('updateguard.restorations', ['module' => $m->name]) }}">{{ __('updateguard::ug.backups') }}</a></li>
                        <li><a href="{{ route('updateguard.logs', ['module' => $m->name]) }}">{{ __('updateguard::ug.logs') }}</a></li>
                    </ul>
                </div>
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
<form method="POST" id="ug-post-form" class="hidden">{{ csrf_field() }}</form>
