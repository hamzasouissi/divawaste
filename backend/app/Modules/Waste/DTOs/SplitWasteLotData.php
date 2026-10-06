<?php

namespace App\Modules\Waste\DTOs;

final readonly class SplitWasteLotData
{
    /**
     * @param  list<array{net_weight_kg: string, zone: string|null}>  $outputs
     */
    public function __construct(public array $outputs, public ?string $notes = null) {}
}
