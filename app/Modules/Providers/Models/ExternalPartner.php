<?php

namespace App\Modules\Providers\Models;

use App\Modules\Audit\Models\Concerns\Auditable;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A transporter or a receiver the company works with and that has no account on the platform. It belongs to the
 * company that recorded it: the company fills in each stage of a pickup on its behalf, and it signs on paper.
 *
 * @property string $ulid
 * @property CarbonImmutable|null $accreditation_expires_on
 */
class ExternalPartner extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasPublicUlid;

    protected $fillable = [
        'ulid', 'legal_name', 'tax_id', 'address_line1', 'postal_code', 'city', 'phone', 'email', 'contact_name',
        'is_transporter', 'is_recycler', 'is_eliminator', 'accreditation_reference', 'accreditation_expires_on', 'covers_hazardous',
        'notes', 'is_active', 'created_by_user_id',
    ];

    protected $attributes = ['is_transporter' => false, 'is_recycler' => false, 'is_eliminator' => false, 'covers_hazardous' => false, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'is_transporter' => 'boolean', 'is_recycler' => 'boolean', 'is_eliminator' => 'boolean', 'covers_hazardous' => 'boolean',
            'is_active' => 'boolean', 'accreditation_expires_on' => 'date',
        ];
    }

    /**
     * May receive waste (recycle or eliminate it), as opposed to only carrying it.
     */
    public function receives(): bool
    {
        return $this->is_recycler || $this->is_eliminator;
    }

    /**
     * What is printed on a tracking slip for this party.
     *
     * @return array{legal_name: string, tax_id: string|null, address: string, phone: string|null}
     */
    public function snapshot(): array
    {
        return ['legal_name' => $this->legal_name, 'tax_id' => $this->tax_id,
            'address' => trim("{$this->address_line1} {$this->postal_code} {$this->city}"), 'phone' => $this->phone];
    }
}
