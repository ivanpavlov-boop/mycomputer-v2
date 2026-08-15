<?php

namespace App\Exceptions;

use LogicException;

final class ImportHistoryTransitionRejectedException extends LogicException
{
    public static function alreadyConsumed(): self
    {
        return new self('Import history terminal transition was already consumed.');
    }

    public static function unexpectedAffectedRows(): self
    {
        return new self('Import history terminal transition did not affect exactly one row.');
    }
}
