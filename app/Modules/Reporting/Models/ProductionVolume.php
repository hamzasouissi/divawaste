<?php

namespace App\Modules\Reporting\Models;

use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Identity\Models\User;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionVolume extends Model
{
    use BelongsToCompany;
    use HasPublicUlid;

    protected $table = 'production_volumes';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'pieces_produced' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by_user_id');
    }
}
