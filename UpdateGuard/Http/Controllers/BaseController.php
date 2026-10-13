<?php

namespace Modules\UpdateGuard\Http\Controllers;

use Modules\UpdateGuard\Entities\UpdateJob;
use Modules\UpdateGuard\Services\Paths;
use Modules\UpdateGuard\Services\UpdateRunner;

/** Administrators only (checked again here, on top of the "roles" middleware of the routes). */
abstract class BaseController extends \App\Http\Controllers\Controller
{
    protected function admin()
    {
        $user = auth()->user();
        if (!$user || !$user->isAdmin()) {
            \Helper::denyAccess();
        }

        return $user;
    }

    protected function moduleName($name)
    {
        if (!Paths::isModuleName($name) || !is_dir(Paths::modules($name))) {
            abort(404);
        }

        return $name;
    }

    /**
     * Queues an operation for the scheduler (updates are never run inside a web request).
     * @return UpdateJob|null null when another operation is already pending for the module
     */
    protected function queue($type, $module, array $attributes = [])
    {
        if ($type !== 'check' && UpdateJob::pendingFor($module)) {
            return null;
        }
        $user = auth()->user();

        return UpdateRunner::queue($type, $module, array_merge([
            'user_id'      => $user->id,
            'triggered_by' => $user->getFullName() ?: 'Administrator',
        ], $attributes));
    }

    protected function queued($job)
    {
        if (!$job) {
            return redirect()->back()->with('ug_error', __('updateguard::ug.already_pending'));
        }

        return redirect()->back()->with('ug_job', $job->id)
            ->with('ug_success', __('updateguard::ug.queued', ['id' => $job->update_id]));
    }
}
