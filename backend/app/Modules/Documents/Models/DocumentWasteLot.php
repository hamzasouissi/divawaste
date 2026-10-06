<?php

namespace App\Modules\Documents\Models;

use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use App\Modules\Waste\Models\WasteLot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentWasteLot extends Model
{
    use BelongsToCompany;

    protected $table = 'document_waste_lots';

    protected $guarded = [
        'id',
        'company_id',
    ];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Company::class,
            'company_id'
        );
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<WasteLot, $this>
     */
    public function wasteLot(): BelongsTo
    {
        return $this->belongsTo(WasteLot::class);
    }
}
