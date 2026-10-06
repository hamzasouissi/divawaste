<?php

namespace App\Modules\Audit\Models\Concerns;

use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Records every creation, modification and deletion of the model in the audit trail (M7-02).
 * Secrets never reach the trail; a model can add columns through `$auditExclude`.
 */
trait Auditable
{
    /** @var list<string> */
    private static array $auditNeverLogged = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'token_hash',
        'created_at', 'updated_at', 'version',
    ];

    protected static function bootAuditable(): void
    {
        static::created(fn (self $model) => $model->audit('created', [], $model->getAttributes()));

        static::updated(function (self $model): void {
            $changes = $model->getChanges();
            $model->audit('updated', array_intersect_key($model->getRawOriginal(), $changes), $changes);
        });

        static::deleted(fn (self $model) => $model->audit('deleted', $model->getRawOriginal()));

        if (method_exists(static::class, 'restored')) {
            static::restored(fn (self $model) => $model->audit('restored'));
        }
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function audit(string $event, array $oldValues = [], array $newValues = []): void
    {
        $excluded = array_flip([...self::$auditNeverLogged, ...($this->auditExclude ?? [])]);
        $oldValues = array_diff_key($oldValues, $excluded);
        $newValues = array_diff_key($newValues, $excluded);

        // An update that only touched excluded columns (timestamps, version…) is not an audit event.
        if ($event === 'updated' && $newValues === []) {
            return;
        }

        AuditLogger::recordModel($this, $event, $oldValues, $newValues);
    }
}
