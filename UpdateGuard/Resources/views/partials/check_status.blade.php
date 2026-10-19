{{-- Last check of the versions; automatic background check when it is more than an hour old (Ui::autoCheck). --}}
@php
    $lastCheck = \Modules\UpdateGuard\Services\Ui::lastFullCheck();
@endphp
<p class="ug-last-check text-help">
    {{ __('updateguard::ug.last_full_check') }}:
    @if ($lastCheck)
        {{ $lastCheck->diffForHumans() }} <small>({{ $lastCheck }})</small>
    @else
        {{ __('updateguard::ug.never') }}
    @endif
</p>
@if (!empty($autoCheck) && !session('ug_job'))
    @include('updateguard::partials.progress', ['jobId' => $autoCheck->id])
@endif
