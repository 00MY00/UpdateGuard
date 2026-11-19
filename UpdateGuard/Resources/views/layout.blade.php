{{-- Common frame: FreeScout layout and Bootstrap classes; Refresh look added by updateguard-refresh.css only when Refresh is active. --}}
@extends('layouts.app')

@section('title', __('updateguard::ug.title'))

@section('stylesheets')
    <link href="{{ asset(\Module::getPublicPath('updateguard').'/css/updateguard.css') }}?v={{ \Modules\UpdateGuard\Services\Ui::version() }}" rel="stylesheet" type="text/css">
    @if (\Modules\UpdateGuard\Services\Ui::refreshActive())
        <link href="{{ asset(\Module::getPublicPath('updateguard').'/css/updateguard-refresh.css') }}?v={{ \Modules\UpdateGuard\Services\Ui::version() }}" rel="stylesheet" type="text/css">
    @endif
@endsection

@section('content')
<div class="container ug-page @if (\Modules\UpdateGuard\Services\Ui::refreshActive()) ug-refresh @endif">
    <div class="ug-header">
        {{-- "← Back": previous UpdateGuard page of this tab, copies skipped (updateguard.js), else the parent page
             given by the view (@section('ug_back', url)); "⌂ Home": the dashboard, from anywhere in one click --}}
        @php $ugBack = trim($__env->yieldContent('ug_back')); @endphp
        <div class="ug-titlebar">
            <h1>{{ __('updateguard::ug.title') }}</h1>
            <div class="ug-nav">
                @if ($ugBack !== '')
                    <a class="btn btn-default btn-sm ug-nav-back" href="{!! $ugBack !!}" data-ug-back>← {{ __('updateguard::ug.nav_back') }}</a>
                @endif
                @unless (Route::is('updateguard.dashboard'))
                    <a class="btn btn-default btn-sm ug-nav-home" href="{{ route('updateguard.dashboard') }}" title="{{ __('updateguard::ug.dashboard') }}">⌂ {{ __('updateguard::ug.nav_home') }}</a>
                @endunless
            </div>
        </div>
        <ul class="nav nav-tabs ug-tabs">
            <li class="{{ Route::is('updateguard.dashboard') ? 'active' : '' }}"><a href="{{ route('updateguard.dashboard') }}">{{ __('updateguard::ug.dashboard') }}</a></li>
            <li class="{{ Route::is('updateguard.module*') ? 'active' : '' }}"><a href="{{ route('updateguard.modules') }}">{{ __('updateguard::ug.modules') }}</a></li>
            <li class="{{ Route::is('updateguard.restor*') ? 'active' : '' }}"><a href="{{ route('updateguard.restorations') }}">{{ __('updateguard::ug.restorations') }}</a></li>
            <li class="{{ Route::is('updateguard.logs') || Route::is('updateguard.report*') ? 'active' : '' }}"><a href="{{ route('updateguard.logs') }}">{{ __('updateguard::ug.logs_reports') }}</a></li>
            <li class="{{ Route::is('updateguard.security*') ? 'active' : '' }}"><a href="{{ route('updateguard.security') }}">{{ __('updateguard::ug.security_reports') }}</a></li>
            <li class="{{ Route::is('updateguard.settings*') ? 'active' : '' }}"><a href="{{ route('updateguard.settings') }}">{{ __('updateguard::ug.settings') }}</a></li>
        </ul>
    </div>
    @if (session('ug_success'))
        <div class="alert alert-success">{{ session('ug_success') }}</div>
    @endif
    @if (session('ug_error'))
        <div class="alert alert-danger">× {{ session('ug_error') }}</div>
    @endif
    @include('updateguard::partials.republished')
    @if (session('ug_job'))
        @include('updateguard::partials.progress', ['jobId' => session('ug_job'), 'follow' => true])
    @endif
    @yield('ug_content')
</div>
@endsection

@section('javascripts')
    <script src="{{ asset(\Module::getPublicPath('updateguard').'/js/updateguard.js') }}?v={{ \Modules\UpdateGuard\Services\Ui::version() }}" {!! \Helper::cspNonceAttr() !!}></script>
@endsection
