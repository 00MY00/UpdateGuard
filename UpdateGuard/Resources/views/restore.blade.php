@extends('updateguard::layout')

@section('ug_content')
    <form method="GET" action="{{ route('updateguard.restorations') }}" class="form-inline ug-filters">
        <select name="module" class="form-control input-sm">
            <option value="">{{ __('updateguard::ug.all_modules') }}</option>
            @foreach ($modules as $name)
                <option value="{{ $name }}" @if ($module === $name) selected @endif>{{ $name }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-default btn-sm">{{ __('updateguard::ug.filter') }}</button>
    </form>
    <p class="text-help">{{ __('updateguard::ug.restore_hint') }}</p>
    @include('updateguard::partials.backups_table', ['backups' => $backups, 'installed' => $installed])
    {{ $backups->appends(['module' => $module])->links() }}
@endsection
