@extends('updateguard::layout')

@php
    // explanation under a setting, when the translation "help_<name>" exists
    $help = function ($name) {
        $key = 'updateguard::ug.help_'.$name;

        return \Illuminate\Support\Facades\Lang::has($key) ? '<div class="ug-setting-help text-help">'.e(__($key)).'</div>' : '';
    };
    // data-ug-depends: greyed out while one of these switches is off (the value is kept and still saved)
    $depends = function ($depends) {
        return $depends ? ' data-ug-depends="'.e(implode(',', (array) $depends)).'"' : '';
    };
    // on / off settings: slider (a real checkbox underneath: keyboard, screen readers and the form work as before)
    $check = function ($name, $dependsOn = null) use ($s, $help, $depends) {
        return '<div class="ug-switch-row"'.$depends($dependsOn).'><label class="ug-switch"><input type="checkbox" name="'.e($name).'" value="1" '.($s[$name] ? 'checked' : '').'>'
            .'<span class="ug-slider" aria-hidden="true"></span><span class="ug-switch-label">'.e(__('updateguard::ug.set_'.$name)).'</span></label>'
            .$help($name).'</div>';
    };
    $select = function ($name, $dependsOn = null) use ($s, $choices, $help, $depends) {
        $html = '<div class="form-group"'.$depends($dependsOn).'><label for="ug-'.e($name).'">'.e(__('updateguard::ug.set_'.$name)).'</label>'
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

        return $html.'</select>'.$help($name).'</div>';
    };
    $number = function ($name, $unit, $min = 1, $max = 100000, $dependsOn = null) use ($s, $help, $depends) {
        return '<div class="form-group"'.$depends($dependsOn).'><label for="ug-'.e($name).'">'.e(__('updateguard::ug.set_'.$name)).'</label>'
            .'<div class="input-group"><input type="number" min="'.(int) $min.'" max="'.(int) $max.'" class="form-control" id="ug-'.e($name).'" name="'.e($name).'" value="'.e($s[$name]).'">'
            .'<span class="input-group-addon">'.e($unit).'</span></div>'.$help($name).'</div>';
    };
@endphp

@section('ug_content')
    <ul class="nav nav-pills ug-subnav">
        @foreach (['updates', 'security', 'blocking', 'virustotal', 'rollback', 'retention', 'notifications', 'advanced'] as $section)
            <li><a href="#ug-{{ $section }}">{{ __('updateguard::ug.section_'.$section) }}</a></li>
        @endforeach
    </ul>
    {{-- saved automatically on every change (updateguard.js); the Save button is for browsers without JavaScript --}}
    <div class="ug-autosave-status text-help" aria-live="polite" data-saving="{{ __('updateguard::ug.saving') }}" data-error="{{ __('updateguard::ug.save_failed') }}"></div>
    <form method="POST" action="{{ route('updateguard.settings.save') }}" class="ug-settings" data-ug-autosave="1">
        {{ csrf_field() }}

        <fieldset id="ug-updates">
            <legend>{{ __('updateguard::ug.section_updates') }}</legend>
            {!! $check('enabled') !!}
            {!! $select('check_frequency', 'enabled') !!}
            <div class="form-group" data-ug-depends="enabled">
                <label for="ug-check_time">{{ __('updateguard::ug.set_check_time') }}</label>
                <input type="time" class="form-control" id="ug-check_time" name="check_time" value="{{ $s['check_time'] }}">
            </div>
            {!! $check('auto_update', 'enabled') !!}
            <p class="text-help">{{ __('updateguard::ug.scheduler') }}: {{ $lastRun ?: __('updateguard::ug.never') }} — <code>* * * * * php artisan schedule:run</code></p>
        </fieldset>

        <fieldset id="ug-security">
            <legend>{{ __('updateguard::ug.section_security') }}</legend>
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
                    {!! $check('sec_check_'.$c, 'security_gate') !!}
                @endforeach

                <h5>{{ __('updateguard::ug.sec_levels') }}</h5>
                <p class="text-help">{{ __('updateguard::ug.sec_levels_help') }}</p>
                {!! $number('sec_level_medium', '/ 100', 1, 100, 'security_gate') !!}
                {!! $number('sec_level_high', '/ 100', 1, 100, 'security_gate') !!}
                {!! $number('sec_level_critical', '/ 100', 1, 100, 'security_gate') !!}

                <h5>{{ __('updateguard::ug.sec_reports_retention') }}</h5>
                {!! $check('sec_save_reports', 'security_gate') !!}
                {!! $number('sec_report_retention_days', __('updateguard::ug.days'), 0, 100000, 'security_gate') !!}
                {!! $number('sec_report_max', '', 0, 100000, 'security_gate') !!}
                {!! $number('sec_cache_days', __('updateguard::ug.days'), 0, 365, 'security_gate') !!}
                <p class="text-help">{{ __('updateguard::ug.sec_zero_unlimited') }} <a href="{{ route('updateguard.security') }}">{{ __('updateguard::ug.security_reports') }}</a></p>
            </div>
            <p class="text-help">{{ __('updateguard::ug.sec_disclaimer') }}</p>
        </fieldset>

        {{-- Blocking rules, by situation: every rule is a switch; an active rule stops the update BEFORE any change --}}
        <fieldset id="ug-blocking">
            <legend>{{ __('updateguard::ug.section_blocking') }}</legend>
            <p class="text-help">{{ __('updateguard::ug.blocking_intro') }}</p>

            <h4>{{ __('updateguard::ug.blocking_always') }}</h4>
            <p class="text-help">{{ __('updateguard::ug.blocking_always_help') }}</p>
            <div class="ug-indent">
                {!! $check('require_sha256') !!}
                {!! $check('sec_block_checksum', 'security_gate') !!}
                {!! $check('sec_block_traversal', 'security_gate') !!}
                {!! $check('sec_block_critical_local', 'security_gate') !!}
                {!! $check('sec_block_score', 'security_gate') !!}
                {!! $number('sec_score_threshold', '/ 100', 1, 100, ['security_gate', 'sec_block_score']) !!}
                {!! $check('sec_block_external', ['security_gate', 'vt_enabled']) !!}
                {!! $check('sec_block_external_unavailable', ['security_gate', 'vt_enabled']) !!}
            </div>

            <h4>{{ __('updateguard::ug.blocking_manual') }}</h4>
            <p class="text-help">{{ __('updateguard::ug.blocking_manual_help') }}</p>
            <div class="ug-indent">
                {!! $select('sec_confirm', 'security_gate') !!}
                {!! $check('sec_allow_override', 'security_gate') !!}
                {!! $check('sec_allow_trust', 'security_gate') !!}
                {!! $check('vt_ask_upload', ['security_gate', 'vt_enabled']) !!}
                {!! $check('vt_auto_upload', ['security_gate', 'vt_enabled']) !!}
            </div>

            <h4>{{ __('updateguard::ug.blocking_auto') }}</h4>
            <p class="text-help">{{ __('updateguard::ug.blocking_auto_help') }}</p>
            <div class="ug-indent">
                {!! $select('auto_update_min_level', 'auto_update') !!}
                {!! $check('sec_auto_require_sha256', 'auto_update') !!}
                {!! $check('sec_auto_require_known', ['auto_update', 'security_gate', 'vt_enabled']) !!}
                {!! $check('sec_auto_block_migrations', 'auto_update') !!}
                {!! $check('sec_auto_block_republished', 'auto_update') !!}
            </div>
        </fieldset>

        <fieldset id="ug-virustotal">
            <legend>VirusTotal</legend>
            <p class="text-help">{{ __('updateguard::ug.vt_intro') }}</p>
            {!! $check('vt_enabled', 'security_gate') !!}
            <div class="ug-indent" data-ug-depends="security_gate,vt_enabled">
                <div class="form-group">
                    <label for="ug-vt_api_key">{{ __('updateguard::ug.vt_api_key') }}</label>
                    <input type="password" class="form-control" id="ug-vt_api_key" name="vt_api_key" value="" autocomplete="new-password"
                           placeholder="{{ $vtKeyMask !== '' ? $vtKeyMask : __('updateguard::ug.vt_api_key_none') }}">
                    <p class="text-help">{{ __('updateguard::ug.vt_api_key_help') }}</p>
                    <div class="ug-key-status" aria-live="polite" data-testing="{{ __('updateguard::ug.vt_key_testing') }}"></div>
                    @if ($vtKeyMask !== '')
                        <div class="ug-switch-row"><label class="ug-switch"><input type="checkbox" name="vt_api_key_remove" value="1"><span class="ug-slider" aria-hidden="true"></span><span class="ug-switch-label">{{ __('updateguard::ug.vt_api_key_remove') }}</span></label></div>
                    @endif
                    <button type="submit" class="btn btn-default btn-sm" formaction="{{ route('updateguard.security.test_vt') }}">{{ __('updateguard::ug.vt_test') }}</button>
                </div>
                {!! $check('vt_hash_first') !!}
                <p class="text-help">{{ __('updateguard::ug.vt_privacy') }}</p>
                {!! $number('vt_block_threshold', __('updateguard::ug.vt_detections'), 1, 100) !!}
                {!! $number('vt_warning_min', __('updateguard::ug.vt_detections'), 1, 100) !!}
                {!! $number('vt_high_min', __('updateguard::ug.vt_detections'), 1, 100) !!}
                {!! $number('vt_critical_min', __('updateguard::ug.vt_detections'), 1, 100) !!}
                {!! $number('vt_timeout', 's', 5, 300) !!}

                <h5>{{ __('updateguard::ug.vt_limits') }}</h5>
                {!! $select('vt_plan') !!}
                <p class="text-help">{{ __('updateguard::ug.vt_limits_help') }}</p>
                {!! $number('vt_rate_minute', '/ min', 1, 10000) !!}
                {!! $number('vt_quota_day', __('updateguard::ug.vt_per_day'), 1, 10000000) !!}
                {!! $number('vt_quota_month', __('updateguard::ug.vt_per_month'), 1, 100000000) !!}
                @if ($vtUsage)
                    <p class="ug-vt-usage">{{ __('updateguard::ug.vt_usage', [
                        'day' => $vtUsage['day'], 'day_max' => $vtUsage['limits']['day'],
                        'month' => $vtUsage['month'], 'month_max' => $vtUsage['limits']['month'],
                    ]) }}</p>
                @endif
                <p class="text-help">{{ __('updateguard::ug.vt_false_positives') }}</p>
            </div>
        </fieldset>

        <fieldset id="ug-rollback">
            <legend>{{ __('updateguard::ug.section_rollback') }}</legend>
            {!! $check('auto_rollback') !!}
            <div class="ug-indent">
                @foreach (['native', 'generic', 'bootstrap', 'http500', 'fatal', 'timeout', 'validation'] as $cause)
                    {!! $check('rollback_'.$cause, 'auto_rollback') !!}
                @endforeach
            </div>
            {!! $select('observation_minutes', 'auto_rollback') !!}
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
