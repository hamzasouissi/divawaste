<?php

namespace App\Modules\Audit\Resources;

use App\Modules\Audit\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actor = $this->relationLoaded('actor') ? $this->getRelation('actor') : null;

        return [
            'event' => $this->event,
            'entity' => $this->auditable_type,
            'object' => $this->auditable_ulid,
            'actor_type' => $this->actor_type,
            'actor' => $actor === null ? null : ['ulid' => $actor->ulid, 'name' => trim("{$actor->first_name} {$actor->last_name}")],
            'source' => $this->source,
            'old_values' => self::withoutInternalIds($this->old_values),
            'new_values' => self::withoutInternalIds($this->new_values),
            'ip_address' => $this->ip_address,
            'request_id' => $this->request_id,
            'occurred_at' => $this->created_at?->toIso8601ZuluString('millisecond'),
        ];
    }

    /**
     * Database identifiers are internal: foreign keys are kept out of the public trail.
     *
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private static function withoutInternalIds(?array $values): ?array
    {
        return $values === null ? null : array_filter(
            $values,
            fn (string $key) => $key !== 'id' && ! str_ends_with($key, '_id'),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
