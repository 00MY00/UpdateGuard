<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables of UpdateGuard (nothing of FreeScout is changed).
 *
 *  updateguard_modules  one row per installed module: versions, capabilities, safety level, health, state,
 *                       last known-good version, blocked version (loop protection)
 *  updateguard_jobs     one row per operation (update, restore, rollback, health check): state machine + update ID
 *  updateguard_backups  one row per backup folder in storage/updateguard/backups/<Module>/<version>-<date>/
 *  updateguard_logs     log lines (timestamp, severity, module, versions, step, message)
 */
class CreateUpdateguardTables extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('updateguard_modules')) {
            Schema::create('updateguard_modules', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 100)->unique();
                $table->string('alias', 100)->default('');
                $table->string('installed_version', 50)->default('');
                $table->string('latest_version', 50)->default('');
                $table->boolean('enabled')->default(false);
                $table->string('safety_level', 20)->default('unsupported');
                $table->text('capabilities')->nullable();
                $table->string('health_status', 30)->default('unknown');
                $table->string('state', 30)->default('stable');
                $table->string('known_good_version', 50)->default('');
                $table->string('blocked_version', 50)->default('');
                $table->string('blocked_reason', 255)->default('');
                $table->boolean('auto_update')->default(true);
                $table->text('last_error')->nullable();
                $table->timestamp('health_checked_at')->nullable();
                $table->timestamp('last_check_at')->nullable();
                $table->timestamp('last_update_at')->nullable();
                $table->timestamp('last_backup_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('updateguard_jobs')) {
            Schema::create('updateguard_jobs', function (Blueprint $table) {
                $table->increments('id');
                $table->string('update_id', 40)->nullable()->unique();
                $table->string('type', 20);
                $table->string('module', 100);
                $table->string('from_version', 50)->default('');
                $table->string('to_version', 50)->default('');
                $table->unsignedInteger('backup_id')->nullable();
                $table->string('status', 30)->default('queued');
                $table->boolean('automatic')->default(false);
                $table->boolean('force')->default(false);
                $table->unsignedInteger('user_id')->nullable();
                $table->string('triggered_by', 191)->default('');
                $table->unsignedTinyInteger('progress')->default(0);
                $table->text('message')->nullable();
                $table->string('rollback_kind', 30)->default('');
                $table->timestamp('installed_at')->nullable();
                $table->timestamp('observe_until')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->index(['module', 'status']);
                $table->index('status');
            });
        }

        if (!Schema::hasTable('updateguard_backups')) {
            Schema::create('updateguard_backups', function (Blueprint $table) {
                $table->increments('id');
                $table->string('module', 100);
                $table->string('version', 50);
                $table->string('path', 255);
                $table->string('reason', 30);
                $table->string('update_id', 40)->default('');
                $table->unsignedBigInteger('size')->default(0);
                $table->string('checksum', 64)->default('');
                $table->string('source', 500)->default('');
                $table->string('health_status', 30)->default('unknown');
                $table->boolean('known_good')->default(false);
                $table->timestamps();
                $table->index('module');
            });
        }

        if (!Schema::hasTable('updateguard_logs')) {
            Schema::create('updateguard_logs', function (Blueprint $table) {
                $table->increments('id');
                $table->string('update_id', 40)->default('');
                $table->string('level', 10);
                $table->string('module', 100)->default('');
                $table->string('from_version', 50)->default('');
                $table->string('to_version', 50)->default('');
                $table->string('step', 40)->default('');
                $table->text('message');
                $table->text('context')->nullable();
                $table->timestamps();
                $table->index('update_id');
                $table->index(['module', 'created_at']);
                $table->index('level');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('updateguard_logs');
        Schema::dropIfExists('updateguard_backups');
        Schema::dropIfExists('updateguard_jobs');
        Schema::dropIfExists('updateguard_modules');
    }
}
