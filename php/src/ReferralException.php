<?php

declare(strict_types=1);

namespace Moonetix\ReferralEngine;

/** Every error the library throws. $errorCode matches the `code` of the JavaScript ReferralError. */
final class ReferralException extends \RuntimeException
{
    public readonly string $errorCode;

    public function __construct(string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }
}
