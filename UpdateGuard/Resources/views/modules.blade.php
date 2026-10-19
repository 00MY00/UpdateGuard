@extends('updateguard::layout')

@section('ug_content')
    <div class="ug-toolbar">
        <form method="POST" action="{{ route('updateguard.check_all') }}" class="ug-inline">
            {{ csrf_field() }}
            <button type="submit" class="btn btn-primary">{{ __('updateguard::ug.check_all') }}</button>
        </form>
        <span class="text-help">{{ __('updateguard::ug.managed_hint') }}</span>
    </div>
    @include('updateguard::partials.check_status')
    @include('updateguard::partials.modules_table', ['modules' => $modules, 'backups' => $backups])
@endsection
