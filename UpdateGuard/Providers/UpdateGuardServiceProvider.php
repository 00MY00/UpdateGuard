<?php

namespace Modules\UpdateGuard\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\UpdateGuard\Services\Settings;
use Modules\UpdateGuard\Services\Ui;

/**
 * UpdateGuard: safe update layer for FreeScout community modules.
 *
 * Nothing of FreeScout or of another module is modified. The module adds its own routes, views, translations,
 * tables and commands, and listens to FreeScout hooks:
 *   menu.manage.after_mailboxes (resources/views/layouts/app.blade.php) -> "UpdateGuard" in Manage (admins)
 *   refresh.rail_items          (Refresh module, when installed and active) -> entry in Refresh's left bar
 *   schedule                    (app/Console/Kernel.php)                -> updateguard:run / check / cleanup
 *
 * Hooks exposed to other modules (README, "For module developers"):
 *   actions  updateguard.before_update, updateguard.after_update, updateguard.update_failed,
 *            updateguard.before_rollback, updateguard.after_rollback, updateguard.backup_created,
 *            updateguard.health_check (after each health check)
 *   filters  updateguard.health_check (extra checks run by the probe), updateguard.health_status
 */
class UpdateGuardServiceProvider extends ServiceProvider
{
    const ALIAS = 'updateguard';

    protected $defer = false;

    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', self::ALIAS);
        $this->commands([
            \Modules\UpdateGuard\Console\CheckUpdatesCommand::class,
            \Modules\UpdateGuard\Console\UpdateModuleCommand::class,
            \Modules\UpdateGuard\Console\RunCommand::class,
            \Modules\UpdateGuard\Console\HealthCheckCommand::class,
            \Modules\UpdateGuard\Console\RestoreModuleCommand::class,
            \Modules\UpdateGuard\Console\CleanupBackupsCommand::class,
            \Modules\UpdateGuard\Console\ProbeCommand::class,
        ]);
    }

    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', self::ALIAS);
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', self::ALIAS);
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Http/routes.php');
        $this->registerMenus();
        $this->registerPhoneMenu();
        $this->registerModulesPageHint();
        $this->registerSchedule();
    }

    /**
     * Phones with the Refresh module: Refresh replaces the left bar by a fixed tab bar (Tickets, Contacts, Dashboard,
     * Notifications, Profile), with no room for a module. The entry is added to the "Profile" panel instead
     * (Refresh's burger menu) by Public/js/updateguard-phone.js, from the settings written here in <head> — same
     * mechanism as Workflow Packs. Without Refresh, the entry is in FreeScout's own burger menu (Manage).
     */
    protected function registerPhoneMenu()
    {
        \Eventy::addFilter('javascripts', function ($scripts) {
            $scripts[] = \Module::getPublicPath(self::ALIAS).'/js/updateguard-phone.js';

            return $scripts;
        });
        \Eventy::addAction('layout.head', function () {
            try {
                if (!self::isAdmin() || !Ui::refreshActive()) {
                    return;
                }
                $config = [
                    'url'    => route('updateguard.dashboard'),
                    'label'  => __('updateguard::ug.title'),
                    'icon'   => asset(\Module::getPublicPath(self::ALIAS).'/img/module.svg'),
                    'active' => self::isModulePage(),
                ];
                echo '<meta name="updateguard" content="'.e(json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)).'">'."\n";
            } catch (\Throwable $e) {
                // optional entry
            }
        });
    }

    /**
     * FreeScout's Manage › Modules page (route "modules"): note recommending UpdateGuard next to the updates FreeScout
     * offers for community modules (never UpdateGuard itself, never official modules). FreeScout's page has no hook:
     * Public/js/updateguard-modules.js adds the note from the settings written here. Setting "modules_page_hint".
     */
    protected function registerModulesPageHint()
    {
        \Eventy::addFilter('javascripts', function ($scripts) {
            try {
                if (\Route::is('modules')) {
                    $scripts[] = \Module::getPublicPath(self::ALIAS).'/js/updateguard-modules.js';
                }
            } catch (\Throwable $e) {
            }

            return $scripts;
        });
        \Eventy::addAction('layout.head', function () {
            try {
                if (!\Route::is('modules') || !self::isAdmin() || !Settings::get('enabled') || !Settings::get('modules_page_hint')) {
                    return;
                }
                $modules = [];
                foreach (\Modules\UpdateGuard\Services\ModuleDiscoveryService::installed() as $name => $module) {
                    if ($name === \Modules\UpdateGuard\Services\ModuleDiscoveryService::SELF
                        || \Modules\UpdateGuard\Services\ModuleDiscoveryService::isOfficial($module['manifest'])) {
                        continue;
                    }
                    $modules[$module['alias']] = ['name' => $name, 'url' => route('updateguard.module', ['name' => $name])];
                }
                $config = [
                    'modules'       => $modules,
                    'text'          => __('updateguard::ug.modules_hint', ['module' => '__MODULE__']),
                    'link'          => __('updateguard::ug.modules_hint_link'),
                    'summary'       => __('updateguard::ug.modules_hint_summary'),
                    'dashboard'     => route('updateguard.dashboard'),
                    'dashboardLink' => __('updateguard::ug.title'),
                ];
                echo '<meta name="updateguard-modules" content="'.e(json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)).'">'."\n";
            } catch (\Throwable $e) {
                // optional note: never break FreeScout's page
            }
        });
    }

    public static function isAdmin()
    {
        $user = auth()->user();

        return $user && $user->isAdmin();
    }

    public static function isModulePage()
    {
        try {
            return \Route::is('updateguard.*');
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function registerMenus()
    {
        \Eventy::addAction('menu.manage.after_mailboxes', function () {
            try {
                if (self::isAdmin()) {
                    $alert = Ui::alert() || Ui::republished()->isNotEmpty() ?' <span class="label label-danger">!</span>' : '';
                    echo '<li class="'.(self::isModulePage() ? 'active' : '').'"><a href="'.e(route('updateguard.dashboard')).'">'
                        .e(__('updateguard::ug.title')).$alert.'</a></li>';
                }
            } catch (\Throwable $e) {
                // optional entry: never break the layout
            }
        });

        // Refresh's left bar (public extension point of Refresh; ignored when Refresh is absent)
        \Eventy::addFilter('refresh.rail_items', function ($items) {
            try {
                if (self::isAdmin()) {
                    $items[] = [
                        'url'    => route('updateguard.dashboard'),
                        'label'  => __('updateguard::ug.title'),
                        'icon'   => asset(\Module::getPublicPath(self::ALIAS).'/img/module.svg'),
                        'active' => self::isModulePage(),
                        'order'  => 70,
                    ];
                }
            } catch (\Throwable $e) {
            }

            return $items;
        });
    }

    protected function registerSchedule()
    {
        \Eventy::addFilter('schedule', function ($schedule) {
            try {
                $schedule->command('updateguard:run')->everyMinute()->withoutOverlapping();
                $check = $schedule->command('updateguard:check')->withoutOverlapping();
                if (Settings::get('check_frequency') === 'hourly') {
                    $check->hourly();
                } else {
                    $time = (string) Settings::get('check_time');
                    $check->dailyAt(preg_match('/^\d{1,2}:\d{2}$/', $time) ? $time : '03:20');
                }
                $schedule->command('updateguard:cleanup')->dailyAt('04:10')->withoutOverlapping();
            } catch (\Throwable $e) {
                // no scheduled task rather than a broken scheduler
            }

            return $schedule;
        });
    }
}
