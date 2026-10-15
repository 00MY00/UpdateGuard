@extends('updateguard::layout')

@php
    // on / off settings: slider (a real checkbox underneath: keyboard, screen readers and the form work as before)
    $check = function ($name) use ($s) {
        return '<div class="ug-switch-row"><label class="ug-switch"><input type="checkbox" name="'.e($name).'" value="1" '.($s[$name] ? 'checked' : '').'>'
            .'<span class="ug-slider" aria-hidden="true"></span><span class="ug-switch-label">'.e(__('updateguard::ug.set_'.$name)).'</span></label></div>';
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
    $number = function ($name, $unit, $min = 1, $max = 100000) use ($s) {
        return '<div class="form-group"><label for="ug-'.e($name).'">'.e(__('updateguard::ug.set_'.$name)).'</label>'
            .'<div class="input-group"><input type="number" min="'.(int) $min.'" max="'.(int) $max.'" class="form-control" id="ug-'.e($name).'" name="'.e($name).'" value="'.e($s[$name]).'">'
            .'<span class="input-group-addon">'.e($unit).'</span></div></div>';
    };
@endphp

@section('ug_content')
    <ul class="nav nav-pills ug-subnav">
        @foreach (['updates', 'security', 'virustotal', 'rollback', 'retention', 'notifications', 'advanced'] as $section)
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
            <div class="ug-switch-row"><label class="ug-switch"><input type="checkbox" checked disabled><span class="ug-slider" aria-hidden="true"></span><span class="ug-switch-label">{{ __('updateguard::ug.set_use_staging') }}</span></label></div>
            <div class="ug-switch-row"><label class="ug-switch"><input type="checkbox" checked disabled><span class="ug-slider" aria-hidden="true"></span><span class="ug-switch-label">{{ __('updateguard::ug.set_backup_before_update') }}</span></label></div>
            <p class="text-help">{{ __('updateguard::ug.always_on') }}</p>
            {!! $check('fallback_validation') !!}

            <h4>{{ __('updateguard::ug.sec_verification') }}</h4>
            <p class="text-help">{{ __('updateguard::ug.sec_intro') }}</p>
            {!! $check('security_gate') !!}
            <div class="ug-indent">
                <h5>{{ __('updateguard::ug.sec_basic_checks') }}</h5>
                @foreach (['integrity', 'structure', 'php', 'obfuscation', 'executables', 'filesystem', 'network', 'installer'] as $c)
                    {!! $check('sec_check_'.$c) !!}
                @endforeach

                <h5>{{ __('updateguard::ug.sec_policy') }}</h5>
                <p class="text-help">{{ __('updateguard::ug.sec_block_if') }}</p>
                {!! $check('sec_block_checksum') !!}
                {!! $check('sec_block_traversal') !!}
                {!! $check('sec_block_critical_local') !!}
                {!! $check('sec_block_score') !!}
                {!! $number('sec_score_threshold', '/ 100', 1, 100) !!}
                {!! $check('sec_block_external') !!}
                {!! $check('sec_block_external_unavailable') !!}

                <h5>{{ __('updateguard::ug.sec_update_behavior') }}</h5>
                {!! $check('sec_allow_override') !!}
                {!! $select('sec_confirm') !!}
                {!! $check('sec_save_reports') !!}

                <h5>{{ __('updateguard::ug.sec_levels') }}</h5>
                <p class="text-help">{{ __('updateguard::ug.sec_levels_help') }}</p>
                {!! $number('sec_level_medium', '/ 100', 1, 100) !!}
                {!! $number('sec_level_high', '/ 100', 1, 100) !!}
                {!! $number('sec_level_critical', '/ 100', 1, 100) !!}

                <h5>{{ __('updateguard::ug.sec_reports_retention') }}</h5>
                {!! $number('sec_report_retention_days', __('updateguard::ug.days'), 0) !!}
                {!! $number('sec_report_max', '', 0) !!}
                {!! $number('sec_cache_days', __('updateguard::ug.days'), 0, 365) !!}
                <p class="text-help">{{ __('updateguard::ug.sec_zero_unlimited') }} <a href="{{ route('updateguard.security') }}">{{ __('updateguard::ug.security_reports') }}</a></p>
            </div>
            <p class="text-help">{{ __('updateguard::ug.sec_disclaimer') }}</p>
        </fieldset>

        <fieldset id="ug-virustotal">
            <legend>VirusTotal</legend>
            <p class="text-help">{{ __('updateguard::ug.vt_intro') }}</p>
            {!! $check('vt_enabled') !!}
            <div class="ug-indent">
                <div class="form-group">
                    <label for="ug-vt_api_key">{{ __('updateguard::ug.vt_api_key') }}</label>
                    <input type="password" class="form-control" id="ug-vt_api_key" name="vt_api_key" value="" autocomplete="new-password"
                           placeholder="{{ $vtKeyMask !== '' ? $vtKeyMask : __('updateguard::ug.vt_api_key_none') }}">
                    <p class="text-help">{{ __('updateguard::ug.vt_api_key_help') }}</p>
                    @if ($vtKeyMask !== '')
                        <div class="ug-switch-row"><label class="ug-switch"><input type="checkbox" name="vt_api_key_remove" value="1"><span class="ug-slider" aria-hidden="true"></span><span class="ug-switch-label">{{ __('updateguard::ug.vt_api_key_remove') }}</span></label></div>
                    @endif
                    <button type="submit" class="btn btn-default btn-sm" formaction="{{ route('updateguard.security.test_vt') }}">{{ __('updateguard::ug.vt_test') }}</button>
                </div>
                {!! $check('vt_hash_first') !!}
                {!! $check('vt_auto_upload') !!}
                {!! $check('vt_ask_upload') !!}
                <p class="text-help">{{ __('updateguard::ug.vt_privacy') }}</p>
                {!! $number('vt_block_threshold', __('updateguard::ug.vt_detections'), 1, 100) !!}
                {!! $number('vt_warning_min', __('updateguard::ug.vt_detections'), 1, 100) !!}
                {!! $number('vt_high_min', __('updateguard::ug.vt_detections'), 1, 100) !!}
                {!! $number('vt_critical_min', __('updateguard::ug.vt_detections'), 1, 100) !!}
                {!! $select('vt_on_failure') !!}
                {!! $number('vt_timeout', 's', 5, 300) !!}
                <p class="text-help">{{ __('updateguard::ug.vt_false_positives') }}</p>
            </div>
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
            {!! $check('modules_page_hint') !!}
            <p class="text-help">{{ __('updateguard::ug.modules_page_hint_help') }}</p>
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
