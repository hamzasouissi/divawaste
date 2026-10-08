<?php

use App\Modules\Catalog\Models\WasteType;
use App\Modules\Catalog\Resources\WasteTypeResource;
use App\Modules\Identity\Authorization\AccessResolver;
use App\Modules\Production\Support\LotOrigin;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Resources\SiteResource;
use App\Modules\Sync\Actions\ApplyOfflineOperations;
use App\Modules\Sync\Actions\RegisterDevice;
use App\Modules\Sync\Requests\RegisterDeviceRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::post('mobile/devices', function (RegisterDeviceRequest $request, RegisterDevice $action) {
        [$device, $token] = $action->handle($request->toData(), $request->user());

        return response()->json(['data' => [
            'device' => $device->ulid,
            'token' => $token,
            'expires_in_days' => RegisterDevice::TOKEN_TTL_DAYS,
        ]], 201);
    })->name('mobile.devices.store');

    /*
     * What the workshop application needs to keep working without a network: the sites and zones where the
     * operator may create lots, and the active waste types.
     */
    Route::get('sync/bootstrap', function (Request $request, AccessResolver $access, LotOrigin $origin) {
        $siteIds = $access->siteIdsFor($request->user(), 'lots.create');
        abort_if($siteIds === [], 403);

        return response()->json(['data' => [
            'server_time' => now()->toIso8601ZuluString('millisecond'),
            'sites' => SiteResource::collection(Site::query()->where('is_active', true)
                ->when($siteIds !== null, fn ($query) => $query->whereIn('id', $siteIds))
                ->with(['zones' => fn ($query) => $query->where('is_active', true)->orderBy('code')])->orderBy('code')->get()),
            'waste_types' => WasteTypeResource::collection(WasteType::query()->where('is_active', true)->orderBy('name')->get()),
            // The manufacturing orders a lot can be attached to on these sites (empty when the company does not use production).
            'manufacturing_orders' => $origin->openOrders($siteIds),
        ]]);
    })->name('sync.bootstrap');

    // M2-08. Only a registered device may push: each stored operation names its device.
    Route::post('sync/push', function (Request $request, ApplyOfflineOperations $action) {
        $token = $request->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && $token->device_id !== null, 403, 'A registered device token is required.');

        $input = $request->validate([
            'sent_at' => ['nullable', 'date'],
            'operations' => ['required', 'array', 'min:1', 'max:100'],
            'operations.*.id' => ['required', 'ulid', 'distinct'],
            'operations.*.sequence' => ['required', 'integer', 'min:0'],
            'operations.*.type' => ['required', Rule::in(ApplyOfflineOperations::TYPES)],
            'operations.*.occurred_at' => ['required', 'date'],
            'operations.*.payload' => ['required', 'array'],
        ]);

        return response()->json(['data' => $action->handle($input['operations'], (int) $token->device_id, $request->user(), $input['sent_at'] ?? null)]);
    })->name('sync.push');
});
