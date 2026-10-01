<?php

namespace App\Modules\Waste\DTOs;

final readonly class GroupWasteLotsData
{
    /**
     * @param  list<string>  $lotUlids
     */
    public function __construct(public array $lotUlids, public string $zoneUlid, public ?string $notes = null) {}
}
