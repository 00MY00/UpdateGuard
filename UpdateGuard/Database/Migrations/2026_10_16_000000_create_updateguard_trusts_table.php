<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Findings of the Security Gate an administrator trusted for a module (1.8.0): rule + file + detail (finding_key),
 * who, when, why, from which package. Revoked trusts are kept (revoked_at) for the audit trail.
 */
class CreateUpdateguardTrustsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('updateguard_trusts')) {
            Schema::create('updateguard_trusts', function (Blueprint $table) {
                $table->increments('id');
                $table->string('module', 100);
                $table->string('finding_key', 40);
                $table->string('rule', 40);
                $table->string('file', 500)->default('');
                $table->text('message');
                $table->string('severity', 10)->default('');
                $table->string('version', 50)->default('');
                $table->string('sha256', 64)->default('');
                $table->unsignedInteger('security_report_id')->nullable();
                $table->text('reason');
                $table->unsignedInteger('user_id')->nullable();
                $table->string('user_name', 191)->default('');
                $table->timestamp('revoked_at')->nullable();
                $table->string('revoked_by', 191)->default('');
                $table->timestamps();
                $table->index(['module', 'revoked_at']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('updateguard_trusts');
    }
}
