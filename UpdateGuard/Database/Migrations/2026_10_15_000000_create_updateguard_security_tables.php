<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security Gate (1.4.0):
 *  updateguard_security_reports  one row per scanned package: integrity, local / external scans, risk, decision,
 *                                override, update result, health check, rollback; full report in JSON
 *  updateguard_jobs              + decisions of the administrator (continue / override / upload), the SHA-256 of
 *                                the package they apply to, and the local archive of an offline update
 * Additive only: existing installations keep working.
 */
class CreateUpdateguardSecurityTables extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('updateguard_security_reports')) {
            Schema::create('updateguard_security_reports', function (Blueprint $table) {
                $table->increments('id');
                $table->string('update_id', 40)->default('');
                $table->string('module', 100);
                $table->string('from_version', 50)->default('');
                $table->string('to_version', 50)->default('');
                $table->string('source', 500)->default('');
                $table->string('sha256', 64)->default('');
                $table->string('integrity', 20)->default('');
                $table->string('local_status', 20)->default('');
                $table->string('external_status', 20)->default('');
                $table->unsignedTinyInteger('score')->default(0);
                $table->string('level', 10)->default('');
                $table->string('decision', 20)->default('');
                $table->string('override_by', 191)->default('');
                $table->string('result', 30)->default('');
                $table->string('health', 30)->default('');
                $table->string('rollback', 30)->default('');
                $table->longText('report');
                $table->timestamps();
                $table->index('module');
                $table->index('update_id');
                $table->index('created_at');
            });
        }
        Schema::table('updateguard_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('updateguard_jobs', 'security_decision')) {
                $table->string('security_decision', 100)->default('');
            }
            if (!Schema::hasColumn('updateguard_jobs', 'security_sha256')) {
                $table->string('security_sha256', 64)->default('');
            }
            if (!Schema::hasColumn('updateguard_jobs', 'security_report_id')) {
                $table->unsignedInteger('security_report_id')->nullable();
            }
            if (!Schema::hasColumn('updateguard_jobs', 'source_path')) {
                $table->string('source_path', 500)->default('');
            }
            if (!Schema::hasColumn('updateguard_jobs', 'sums_path')) {
                $table->string('sums_path', 500)->default('');
            }
        });
    }

    public function down()
    {
        Schema::dropIfExists('updateguard_security_reports');
        Schema::table('updateguard_jobs', function (Blueprint $table) {
            foreach (['security_decision', 'security_sha256', 'security_report_id', 'source_path', 'sums_path'] as $column) {
                if (Schema::hasColumn('updateguard_jobs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}
