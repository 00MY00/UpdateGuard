<?php

namespace Modules\UpdateGuard\Entities;

use Illuminate\Database\Eloquent\Model;

/** One log line (table updateguard_logs). */
class UpdateLog extends Model
{
    protected $table = 'updateguard_logs';

    protected $guarded = ['id'];
}
