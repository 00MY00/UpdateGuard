<?php

namespace Modules\UpdateGuard\Entities;

use Illuminate\Database\Eloquent\Model;

/** A finding of the Security Gate trusted by an administrator for a module (table updateguard_trusts). */
class Trust extends Model
{
    protected $table = 'updateguard_trusts';

    protected $guarded = ['id'];

    protected $dates = ['revoked_at'];

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }
}
