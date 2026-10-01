<?php

use App\Modules\Sync\Actions\RegisterDevice;
use App\Modules\Sync\Requests\RegisterDeviceRequest;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->post('mobile/devices', function (RegisterDeviceRequest $request, RegisterDevice $action) {
    [$device, $token] = $action->handle($request->toData(), $request->user());

    return response()->json(['data' => [
        'device' => $device->ulid,
        'token' => $token,
        'expires_in_days' => RegisterDevice::TOKEN_TTL_DAYS,
    ]], 201);
})->name('mobile.devices.store');
