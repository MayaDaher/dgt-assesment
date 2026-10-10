<?php

namespace App\Exceptions\Auth;

use App\Exceptions\ApiException;

class InvalidCredentialsException extends ApiException
{
    protected int $status = 401;

    protected string $errorCode = 'INVALID_CREDENTIALS';

    public function __construct()
    {
        parent::__construct('The provided credentials are incorrect.');
    }
}
