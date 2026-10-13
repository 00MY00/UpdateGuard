@extends('updateguard::layout')

@section('ug_content')
    <div class="panel panel-default ug-confirm">
        <div class="panel-heading"><strong>{{ __('updateguard::ug.confirm_restore_title', ['module' => $backup->module, 'version' => $backup->version]) }}</strong></div>
        <div class="panel-body">
            <dl class="ug-compare">
                <dt>{{ __('updateguard::ug.current') }}</dt><dd>{{ $backup->module }} {{ $current ?: '—' }}</dd>
                <dt>{{ __('updateguard::ug.restore') }}</dt><dd>{{ $backup->module }} {{ $backup->version }}</dd>
                <dt>{{ __('updateguard::ug.backup') }}</dt><dd>{{ $backup->created_at }} · {{ \Modules\UpdateGuard\Services\Ui::size($backup->size) }}</dd>
                <dt>{{ __('updateguard::ug.status') }}</dt><dd>{{ $backup->known_good ? '✓ '.__('updateguard::ug.known_good') : '! '.__('updateguard::ug.not_known_good') }}</dd>
            </dl>
            <p>{{ __('updateguard::ug.restore_backup_first', ['version' => $current ?: '?']) }}</p>
            <p class="text-help">{{ __('updateguard::ug.restore_db_warning') }}</p>
            @if ($self)
                <div class="alert alert-warning">{{ __('updateguard::ug.not_restorable') }}</div>
            @elseif (!$valid)
                <div class="alert alert-danger">× {{ __('updateguard::ug.backup_invalid') }}</div>
            @elseif ($pending)
                <div class="alert alert-warning">! {{ __('updateguard::ug.already_pending') }}</div>
            @endif
            <div class="ug-confirm-buttons">
                <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('updateguard.restorations') }}" class="btn btn-default">{{ __('updateguard::ug.cancel') }}</a>
                @if (!$self && $valid && !$pending)
                    <form method="POST" action="{{ route('updateguard.restore', ['id' => $backup->id]) }}" class="ug-inline">
                        {{ csrf_field() }}
                        <button type="submit" class="btn btn-primary">↺ {{ __('updateguard::ug.restore_version', ['version' => $backup->version]) }}</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
@endsection
