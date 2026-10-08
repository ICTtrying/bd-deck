<?php

namespace App\Exceptions;

use RuntimeException;

class VaultLockedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('De kluis is vergrendeld. Log opnieuw in om geheimen te gebruiken.'));
    }
}
