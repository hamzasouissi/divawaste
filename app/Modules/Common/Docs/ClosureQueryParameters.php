<?php

namespace App\Modules\Common\Docs;

final class ClosureQueryParameters extends ClosureParameters
{
    protected function inQuery(): bool
    {
        return true;
    }
}
