<?php

namespace App\Modules\Audit\Models\Concerns;

use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            $model->audit('created');
        });

        static::updated(function (Model $model): void {
            $dirty = $model->getDirty();

            if ($dirty === []) {
                return;
            }

            $model->audit('updated', $model->getOriginal(), $dirty);
        });

        static::deleted(function (Model $model): void {
            $model->audit('deleted', $model->getOriginal(), [
                'deleted_at' => $model->deleted_at ?? now(),
            ]);
        });

        static::restored(function (Model $model): void {
            $model->audit('restored', $model->getOriginal(), [
                'restored_at' => now(),
            ]);
        });
    }

    public function audit(string $event, array $oldValues = [], array $newValues = []): void
    {
        $exclusions = property_exists($this, 'auditExclude') ? $this->auditExclude : [];
        $filteredOld = $this->filterAuditPayload($oldValues, $exclusions);
        $filteredNew = $this->filterAuditPayload($newValues, $exclusions);

        AuditLogger::recordModel($this, $event, $filteredOld, $filteredNew);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $exclusions
     * @return array<string, mixed>
     */
    protected function filterAuditPayload(array $values, array $exclusions): array
    {
        foreach ($values as $key => $value) {
            if (in_array($key, $exclusions, true)) {
                unset($values[$key]);
            }
        }

        return $values;
    }
}
