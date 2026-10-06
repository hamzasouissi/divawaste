<?php

namespace App\Modules\Sync\Actions;

use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Identity\Models\User;
use App\Modules\Sync\DTOs\RegisterDeviceData;
use App\Modules\Sync\Models\Device;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Registers (or re-registers) a device of the current company and issues a token bound to company + device.
 */
final class RegisterDevice
{
    public const TOKEN_TTL_DAYS = 30;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array{Device, string} device and plain-text token
     */
    public function handle(RegisterDeviceData $data, User $user): array
    {
        return DB::transaction(function () use ($data, $user) {
            // Device ULIDs are global: a ULID already used by another company is refused without revealing it.
            $owner = DB::table('devices')->where('ulid', $data->ulid)->value('company_id');
            if ($owner !== null && (int) $owner !== $this->context->companyId()) {
                throw new BusinessRuleViolation('DEVICE_ID_TAKEN', 'This device identifier cannot be used.');
            }

            $device = Device::query()->where('ulid', $data->ulid)->first();
            if ($device?->isRevoked()) {
                throw new BusinessRuleViolation('DEVICE_REVOKED', 'This device has been revoked.');
            }

            $device ??= new Device(['ulid' => $data->ulid, 'registered_by_user_id' => $user->id]);
            $device->fill(['name' => $data->name, 'platform' => $data->platform, 'app_version' => $data->appVersion,
                'user_agent' => $data->userAgent, 'last_seen_at' => now()])->save();

            $token = $user->createToken("device:{$device->ulid}", ['mobile:*'], now()->addDays(self::TOKEN_TTL_DAYS));
            $token->accessToken->forceFill(['company_id' => $device->company_id, 'device_id' => $device->id])->save();

            return [$device, $token->plainTextToken];
        });
    }
}
