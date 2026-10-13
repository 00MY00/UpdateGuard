@extends('updateguard::layout')

@php
    $check = function ($name) use ($s) {
        return '<div class="checkbox"><label><input type="checkbox" name="'.e($name).'" value="1" '.($s[$name] ? 'checked' : '').'> '
            .e(__('updateguard::ug.set_'.$name)).'</label></div>';
    };
    $select = function ($name, $label = null) use ($s, $choices) {
        $html = '<div class="form-group"><label for="ug-'.e($name).'">'.e(__('updateguard::ug.set_'.$name)).'</label>'
            .'<select class="form-control" id="ug-'.e($name).'" name="'.e($name).'">';
        foreach ($choices[$name] as $value) {
            $text = $value === 0 ? __('updateguard::ug.unlimited') : (is_int($value) ? $value : __('updateguard::ug.choice_'.$value));
            if ($name === 'observation_minutes') {
                $text = $value === 0 ? __('updateguard::ug.no_observation') : $value.' min';
            } elseif (is_int($value) && $value !== 0 && strpos($name, 'days') !== false) {
                $text = $value.' '.__('updateguard::ug.days');
            }
            $html .= '<option value="'.e($value).'" '.((string) $s[$name] === (string) $value ? 'selected' : '').'>'.e($text).'</option>';
        }

        return $html.'</select></div>';
    };
    $number = function ($name, $unit) use ($s) {
        return '<div class="form-group"><label for="ug-'.e($name).'">'.e(__('updateguard::ug.set_'.$name)).'</label>'
            .'<div class="input-group"><input type="number" min="1" class="form-control" id="ug-'.e($name).'" name="'.e($name).'" value="'.e($s[$name]).'">'
            .'<span class="input-group-addon">'.e($unit).'</span></div></div>';
    };
@endphp

@section('ug_content')
    <ul class="nav nav-pills ug-subnav">
        @foreach (['updates', 'security', 'rollback', 'retention', 'notifications', 'advanced'] as $section)
            <li><a href="#ug-{{ $section }}">{{ __('updateguard::ug.section_'.$section) }}</a></li>
        @endforeach
    </ul>
    <form method="POST" action="{{ route('updateguard.settings.save') }}" class="ug-settings">
        {{ csrf_field() }}

        <fieldset id="ug-updates">
            <legend>{{ __('updateguard::ug.section_updates') }}</legend>
            {!! $check('enabled') !!}
            {!! $select('check_frequency') !!}
            <div class="form-group">
                <label for="ug-check_time">{{ __('updateguard::ug.set_check_time') }}</label>
                <input type="time" class="form-control" id="ug-check_time" name="check_time" value="{{ $s['check_time'] }}">
            </div>
            {!! $check('auto_update') !!}
            {!! $select('auto_update_min_level') !!}
            <p class="text-help">{{ __('updateguard::ug.auto_update_rules') }}</p>
            <p class="text-help">{{ __('updateguard::ug.scheduler') }}: {{ $lastRun ?: __('updateguard::ug.never') }} — <code>* * * * * php artisan schedule:run</code></p>
        </fieldset>

        <fieldset id="ug-security">
            <legend>{{ __('updateguard::ug.section_security') }}</legend>
            {!! $check('require_sha256') !!}
            <div class="checkbox"><label><input type="checkbox" checked disabled> {{ __('updateguard::ug.set_use_staging') }}</label></div>
            <div class="checkbox"><label><input type="checkbox" checked disabled> {{ __('updateguard::ug.set_backup_before_update') }}</label></div>
            <p class="text-help">{{ __('updateguard::ug.always_on') }}</p>
            {!! $check('fallback_validation') !!}
        </fieldset>

        <fieldset id="ug-rollback">
            <legend>{{ __('updateguard::ug.section_rollback') }}</legend>
            {!! $check('auto_rollback') !!}
            <div class="ug-indent">
                @foreach (['native', 'generic', 'bootstrap', 'http500', 'fatal', 'timeout', 'validation'] as $cause)
                    {!! $check('rollback_'.$cause) !!}
                @endforeach
            </div>
            {!! $select('observation_minutes') !!}
            <p class="text-help">{{ __('updateguard::ug.rollback_db_hint') }}</p>
        </fieldset>

        <fieldset id="ug-retention">
            <legend>{{ __('updateguard::ug.section_retention') }}</legend>
            {!! $select('backups_per_module') !!}
            {!! $select('backup_max_age_days') !!}
            {!! $select('log_retention_days') !!}
            {!! $select('report_retention_days') !!}
            <p class="text-help">{{ __('updateguard::ug.retention_hint') }}</p>
        </fieldset>

        <fieldset id="ug-notifications">
            <legend>{{ __('updateguard::ug.section_notifications') }}</legend>
            {!! $check('notify_banner') !!}
            {!! $check('notify_log') !!}
        </fieldset>

        <fieldset id="ug-advanced">
            <legend>{{ __('updateguard::ug.section_advanced') }}</legend>
            {!! $number('lock_timeout_minutes', 'min') !!}
            {!! $number('process_timeout', 's') !!}
            {!! $number('download_timeout', 's') !!}
            {!! $number('min_free_mb', 'MB') !!}
        </fieldset>

        <div class="ug-save">
            <button type="submit" class="btn btn-primary">{{ __('updateguard::ug.save') }}</button>
        </div>
    </form>
@endsection
