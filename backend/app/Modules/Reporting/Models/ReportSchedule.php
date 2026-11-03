<?php

namespace App\Modules\Reporting\Models;

use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A report someone receives every month, on the month before (R-03).
 *
 * @property string $ulid
 */
class ReportSchedule extends Model
{
    use BelongsToCompany;
    use HasPublicUlid;

    protected $table = 'report_schedules';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_run_on' => 'date'];
    }
}
