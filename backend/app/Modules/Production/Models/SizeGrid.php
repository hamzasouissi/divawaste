<?php

namespace App\Modules\Production\Models;

use App\Modules\Audit\Models\Concerns\Auditable;
use App\Modules\Common\Models\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's list of sizes, in order (Adulte: XS S M L XL XXL), chosen by its OFs.
 *
 * @property string $ulid
 * @property list<string> $sizes
 */
class SizeGrid extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasPublicUlid;

    protected $fillable = ['ulid', 'name', 'sizes', 'is_active'];

    protected function casts(): array
    {
        return ['sizes' => 'array', 'is_active' => 'boolean'];
    }
}
