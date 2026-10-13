<?php

namespace Modules\UpdateGuard\Entities;

use Illuminate\Database\Eloquent\Model;

/** State of one installed module as seen by UpdateGuard (table updateguard_modules). */
class ModuleHealth extends Model
{
    protected $table = 'updateguard_modules';

    protected $guarded = ['id'];

    protected $casts = [
        'enabled'     => 'boolean',
        'auto_update' => 'boolean',
    ];

    protected $dates = ['health_checked_at', 'last_check_at', 'last_update_at', 'last_backup_at'];

    public function capabilities()
    {
        $data = json_decode((string) $this->capabilities, true);

        return is_array($data) ? $data : [];
    }

    public function capability($key, $default = null)
    {
        $c = $this->capabilities();

        return array_key_exists($key, $c) ? $c[$key] : $default;
    }

    public function updateAvailable()
    {
        return \Modules\UpdateGuard\Services\VersionService::isNewer($this->latest_version, $this->installed_version);
    }

    public static function named($name)
    {
        return self::where('name', $name)->first();
    }
}
