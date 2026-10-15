<?php

namespace Modules\UpdateGuard\Entities;

use Illuminate\Database\Eloquent\Model;

/** One Security Gate report (table updateguard_security_reports). */
class SecurityReport extends Model
{
    protected $table = 'updateguard_security_reports';

    protected $guarded = ['id'];

    public function data()
    {
        $data = json_decode((string) $this->report, true);

        return is_array($data) ? $data : [];
    }
}
