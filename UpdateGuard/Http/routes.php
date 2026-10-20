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
    Route::get('/modules/{name}/update', $admins + ['uses' => 'ModulesController@confirmUpdate'])->where('name', '[A-Za-z0-9_-]+')->name('updateguard.module.update.confirm');
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

    // Security Gate: reports, decisions on a paused update, clean-up, VirusTotal connection test
    Route::get('/security', $admins + ['uses' => 'SecurityController@index'])->name('updateguard.security');
    Route::post('/security/cleanup', $admins + ['uses' => 'SecurityController@cleanup'])->name('updateguard.security.cleanup');
    Route::get('/security/{id}', $admins + ['uses' => 'SecurityController@show'])->where('id', '[0-9]+')->name('updateguard.security.show');
    Route::post('/security/{id}/trust', $admins + ['uses' => 'SecurityController@trust'])->where('id', '[0-9]+')->name('updateguard.security.trust');
    Route::post('/trusts/{id}/revoke', $admins + ['uses' => 'SecurityController@revokeTrust'])->where('id', '[0-9]+')->name('updateguard.trust.revoke');
    Route::post('/security/{id}/upload', $admins + ['uses' => 'SecurityController@upload'])->where('id', '[0-9]+')->name('updateguard.security.upload');
    Route::post('/self/upload', $admins + ['uses' => 'SecurityController@uploadSelf'])->name('updateguard.self.upload');
    // security scans without installing: all modules, or one module
    Route::post('/scan-all', $admins + ['uses' => 'SecurityController@scanAll'])->name('updateguard.scan_all');
    Route::post('/modules/{name}/scan', $admins + ['uses' => 'SecurityController@scanModule'])->where('name', '[A-Za-z0-9_\-]+')->name('updateguard.module.scan');
    Route::post('/security/{id}/decide', $admins + ['uses' => 'SecurityController@decide'])->where('id', '[0-9]+')->name('updateguard.security.decide');
    Route::post('/settings/virustotal/test', $admins + ['uses' => 'SecurityController@testVirusTotal'])->name('updateguard.security.test_vt');

    Route::get('/settings', $admins + ['uses' => 'SettingsController@index'])->name('updateguard.settings');
    Route::post('/settings', $admins + ['uses' => 'SettingsController@save'])->name('updateguard.settings.save');
});
