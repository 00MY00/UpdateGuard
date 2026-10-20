@extends('updateguard::layout')

{{-- Before an update: project compatibility of the new version, then Cancel / Update / Install anyway --}}
@section('ug_content')
    @php $blocked = $compat['decision']['decision'] === 'blocked'; @endphp
    <h2 class="ug-title">{{ __('updateguard::ug.compat_confirm_title', ['module' => $module->name]) }}
        <small>{{ $module->installed_version }} → {{ $module->latest_version ?: '?' }}</small></h2>

    @if ($pending)
        <div class="alert alert-warning">! {{ __('updateguard::ug.already_pending') }}</div>
        @include('updateguard::partials.progress', ['jobId' => $pending->id, 'follow' => true])
    @endif

    <h3>{{ __('updateguard::ug.compat_checks') }}</h3>
    @include('updateguard::partials.compatibility', ['c' => $compat])
    <p class="text-help">{{ __('updateguard::ug.compat_checked_again') }}</p>

    @if ($blocked)
        <div class="alert alert-danger">
            × {{ __('updateguard::ug.compat_blocked') }}
            <ul>@foreach ($compat['decision']['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach</ul>
        </div>
    @elseif ($compat['decision']['code'] === 'unknown')
        <div class="alert alert-info">{{ __('updateguard::ug.compat_unknown_help') }}</div>
    @elseif ($compat['decision']['code'] === 'degraded')
        <div class="alert alert-warning">! {{ __('updateguard::ug.compat_degraded_help') }}</div>
    @endif

    <div class="ug-toolbar">
        <a class="btn btn-default" href="{{ route('updateguard.module', ['name' => $module->name]) }}">{{ __('updateguard::ug.compat_cancel') }}</a>
        @if (!$pending)
            @if (!$blocked)
                <form method="POST" action="{{ route('updateguard.module.update', ['name' => $module->name]) }}" class="ug-inline">
                    {{ csrf_field() }}
                    @if ($module->blocked_version !== '' && $module->blocked_version === $module->latest_version)
                        <label class="ug-force"><input type="checkbox" name="force" value="1"> {{ __('updateguard::ug.force_blocked') }}</label>
                    @endif
                    <button type="submit" class="btn btn-primary">↑ {{ __('updateguard::ug.update') }} {{ $module->latest_version }}</button>
                </form>
            @elseif ($compat['decision']['overridable'])
                <form method="POST" action="{{ route('updateguard.module.update', ['name' => $module->name]) }}" class="ug-inline ug-override"
                      data-ug-confirm="{{ __('updateguard::ug.compat_override_confirm') }}">
                    {{ csrf_field() }}
                    <input type="hidden" name="compat_override" value="1">
                    <label><input type="checkbox" name="understand" value="1" required> {{ __('updateguard::ug.compat_override_understand') }}</label>
                    <button type="submit" class="btn btn-danger">{{ __('updateguard::ug.compat_install_anyway') }}</button>
                </form>
            @endif
        @endif
    </div>
@endsection
