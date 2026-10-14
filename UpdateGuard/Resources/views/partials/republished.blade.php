{{-- Alert: a version number was published again with another SHA-256 (RepublishGuard). Automatic updates of the module are paused. --}}
@foreach (\Modules\UpdateGuard\Services\Ui::republished() as $rp)
    @php $info = $rp->capability('republished'); @endphp
    <div class="alert alert-warning ug-republished">
        <strong>! {{ __('updateguard::ug.republished_title') }}</strong><br>
        {{ __('updateguard::ug.republished_text', [
            'module'  => $rp->name,
            'version' => $info['version'],
            'old'     => \Modules\UpdateGuard\Services\RepublishGuard::short($info['old']),
            'new'     => \Modules\UpdateGuard\Services\RepublishGuard::short($info['new']),
        ]) }}
        <div class="ug-republished-actions">
            <form method="POST" action="{{ route('updateguard.module.update', ['name' => $rp->name]) }}" class="ug-inline"
                  data-ug-confirm="{{ __('updateguard::ug.confirm_republished', ['module' => $rp->name, 'version' => $info['version']]) }}">
                {{ csrf_field() }}
                <input type="hidden" name="force" value="1">
                <button type="submit" class="btn btn-warning btn-sm">↑ {{ __('updateguard::ug.republished_action', ['version' => $info['version']]) }}</button>
            </form>
            <span class="text-help">{{ __('updateguard::ug.republished_hint') }}</span>
        </div>
    </div>
@endforeach
