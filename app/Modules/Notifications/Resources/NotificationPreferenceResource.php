<?php

namespace App\Modules\Notifications\Resources;

use App\Modules\Notifications\Models\NotificationPreference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NotificationPreference
 */
class NotificationPreferenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'company_id' => $this->company_id,
            'user_id' => $this->user_id,
            'notification_type' => $this->notification_type,
            'channel' => $this->channel,
            'is_enabled' => $this->is_enabled,
        ];
    }
}
