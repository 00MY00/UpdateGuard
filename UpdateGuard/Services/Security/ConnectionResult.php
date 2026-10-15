<?php

namespace Modules\UpdateGuard\Services\Security;

/** Result of "Test connection" for a provider. Pure PHP. */
class ConnectionResult
{
    /** @var bool */
    public $ok;

    /** @var string */
    public $message;

    public function __construct($ok, $message = '')
    {
        $this->ok = (bool) $ok;
        $this->message = (string) $message;
    }
}
