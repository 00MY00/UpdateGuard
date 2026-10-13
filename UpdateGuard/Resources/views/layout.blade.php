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
        <h1>{{ __('updateguard::ug.title') }}</h1>
        <ul class="nav nav-tabs ug-tabs">
            <li class="{{ Route::is('updateguard.dashboard') ? 'active' : '' }}"><a href="{{ route('updateguard.dashboard') }}">{{ __('updateguard::ug.dashboard') }}</a></li>
            <li class="{{ Route::is('updateguard.module*') ? 'active' : '' }}"><a href="{{ route('updateguard.modules') }}">{{ __('updateguard::ug.modules') }}</a></li>
            <li class="{{ Route::is('updateguard.restor*') ? 'active' : '' }}"><a href="{{ route('updateguard.restorations') }}">{{ __('updateguard::ug.restorations') }}</a></li>
            <li class="{{ Route::is('updateguard.logs') || Route::is('updateguard.report*') ? 'active' : '' }}"><a href="{{ route('updateguard.logs') }}">{{ __('updateguard::ug.logs_reports') }}</a></li>
            <li class="{{ Route::is('updateguard.settings*') ? 'active' : '' }}"><a href="{{ route('updateguard.settings') }}">{{ __('updateguard::ug.settings') }}</a></li>
        </ul>
    </div>
    @if (session('ug_success'))
        <div class="alert alert-success">{{ session('ug_success') }}</div>
    @endif
    @if (session('ug_error'))
        <div class="alert alert-danger">× {{ session('ug_error') }}</div>
    @endif
    @if (session('ug_job'))
        @include('updateguard::partials.progress', ['jobId' => session('ug_job')])
    @endif
    @yield('ug_content')
</div>
@endsection

@section('javascripts')
    <script src="{{ asset(\Module::getPublicPath('updateguard').'/js/updateguard.js') }}?v={{ \Modules\UpdateGuard\Services\Ui::version() }}" {!! \Helper::cspNonceAttr() !!}></script>
@endsection
