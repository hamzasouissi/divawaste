<?php

namespace App\Modules\Common\Docs;

final class ClosureBodyParameters extends ClosureParameters
{
    protected function inQuery(): bool
    {
        return false;
    }
}
