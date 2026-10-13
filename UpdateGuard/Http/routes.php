<?php

/*
 * Same pattern as the other FreeScout modules: "web" group (CSRF), FreeScout's subdirectory prefix, "auth" + "roles".
 * Every page of UpdateGuard is for administrators only (updates, restores, backups, reports, settings).
 */
Route::group([
    'middleware' => 'web',
    'prefix'     => \Helper::getSubdirectory().'/update-guard',
    'namespace'  => 'Modules\UpdateGuard\Http\Controllers',
], function () {
    $admins = ['middleware' => ['auth', 'roles'], 'roles' => ['admin']];

    Route::get('/', $admins + ['uses' => 'DashboardController@index'])->name('updateguard.dashboard');
    Route::post('/check-all', $admins + ['uses' => 'DashboardController@checkAll'])->name('updateguard.check_all');
    Route::get('/jobs/{id}', $admins + ['uses' => 'DashboardController@job'])->where('id', '[0-9]+')->name('updateguard.job');

    Route::get('/modules', $admins + ['uses' => 'ModulesController@index'])->name('updateguard.modules');
    Route::get('/modules/{name}', $admins + ['uses' => 'ModulesController@show'])->where('name', '[A-Za-z0-9_\-]+')->name('updateguard.module');
    Route::post('/modules/{name}/check', $admins + ['uses' => 'ModulesController@check'])->where('name', '[A-Za-z0-9_\-]+')->name('updateguard.module.check');
    Route::post('/modules/{name}/update', $admins + ['uses' => 'ModulesController@update'])->where('name', '[A-Za-z0-9_\-]+')->name('updateguard.module.update');
    Route::post('/modules/{name}/health', $admins + ['uses' => 'ModulesController@health'])->where('name', '[A-Za-z0-9_\-]+')->name('updateguard.module.health');
    Route::post('/modules/{name}/auto', $admins + ['uses' => 'ModulesController@auto'])->where('name', '[A-Za-z0-9_\-]+')->name('updateguard.module.auto');
    Route::post('/modules/{name}/unblock', $admins + ['uses' => 'ModulesController@unblock'])->where('name', '[A-Za-z0-9_\-]+')->name('updateguard.module.unblock');

    Route::get('/restorations', $admins + ['uses' => 'RestoreController@index'])->name('updateguard.restorations');
    Route::get('/restorations/{id}', $admins + ['uses' => 'RestoreController@confirm'])->where('id', '[0-9]+')->name('updateguard.restore.confirm');
    Route::post('/restorations/{id}', $admins + ['uses' => 'RestoreController@restore'])->where('id', '[0-9]+')->name('updateguard.restore');
    Route::get('/restorations/{id}/download', $admins + ['uses' => 'RestoreController@download'])->where('id', '[0-9]+')->name('updateguard.restore.download');
    Route::post('/restorations/{id}/delete', $admins + ['uses' => 'RestoreController@delete'])->where('id', '[0-9]+')->name('updateguard.restore.delete');

    Route::get('/logs', $admins + ['uses' => 'LogsController@index'])->name('updateguard.logs');
    Route::get('/reports/{updateId}', $admins + ['uses' => 'LogsController@report'])->where('updateId', 'UG-[0-9]{8}-[0-9]+')->name('updateguard.report');
    Route::get('/reports/{updateId}/download/{format}', $admins + ['uses' => 'LogsController@download'])->where(['updateId' => 'UG-[0-9]{8}-[0-9]+', 'format' => 'txt|json|html'])->name('updateguard.report.download');
    Route::post('/reports/{updateId}/delete', $admins + ['uses' => 'LogsController@delete'])->where('updateId', 'UG-[0-9]{8}-[0-9]+')->name('updateguard.report.delete');

    Route::get('/settings', $admins + ['uses' => 'SettingsController@index'])->name('updateguard.settings');
    Route::post('/settings', $admins + ['uses' => 'SettingsController@save'])->name('updateguard.settings.save');
});
