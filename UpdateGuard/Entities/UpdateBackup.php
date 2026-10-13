<?php

namespace Modules\UpdateGuard\Entities;

use Illuminate\Database\Eloquent\Model;
use Modules\UpdateGuard\Services\Paths;

/** One backup folder: storage/updateguard/backups/<path>/{files/, metadata.json} (table updateguard_backups). */
class UpdateBackup extends Model
{
    protected $table = 'updateguard_backups';

    protected $guarded = ['id'];

    protected $casts = ['known_good' => 'boolean'];

    public function dir()
    {
        // path is built by BackupService (Module/version-date): never taken from a request
        return Paths::backups($this->path);
    }

    public function filesDir()
    {
        return $this->dir().'/files';
    }

    public function exists()
    {
        return is_file($this->filesDir().'/module.json');
    }
}
