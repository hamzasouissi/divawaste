<?php

use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Resources\NotificationResource;
use App\Modules\Notifications\Support\NotificationCatalog;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

/*
 * M7-01: in-app notifications of the signed-in user, limited to the company he is working in.
 */
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    $inbox = fn (Request $request) => $request->user()->notifications()
        ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', app(TenantContext::class)->companyId()));

    Route::get('me/notifications', fn (Request $request) => NotificationResource::collection(
        $inbox($request)->when($request->boolean('unread'), fn ($query) => $query->whereNull('read_at'))->cursorPaginate(20)
    )->additional(['meta' => ['unread_count' => $inbox($request)->whereNull('read_at')->count()]]))->name('me.notifications.index');

    Route::post('me/notifications/{notification}/read', function (Request $request, string $notification) use ($inbox) {
        // Looked up inside the user's own inbox: another user's notification is simply not found.
        $model = $inbox($request)->findOrFail($notification);
        $model->markAsRead();

        return new NotificationResource($model);
    })->name('me.notifications.read');

    Route::post('me/notifications/read-all', function (Request $request) use ($inbox) {
        $inbox($request)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->noContent();
    })->name('me.notifications.read-all');

    Route::get('me/notification-preferences', function (Request $request) {
        $disabled = NotificationPreference::query()->where('user_id', $request->user()->id)->where('is_enabled', false)
            ->get(['notification_type', 'channel'])->map(fn ($row) => $row->notification_type.'|'.$row->channel)->all();

        return response()->json(['data' => collect(NotificationCatalog::TYPES)->map(fn (array $definition, string $type) => [
            'notification_type' => $type,
            'database' => ! in_array($type.'|database', $disabled, true),
            'mail' => $definition['mail'] && ! in_array($type.'|mail', $disabled, true),
            'mail_available' => $definition['mail'],
        ])->values()]);
    })->name('me.notification-preferences.index');

    Route::put('me/notification-preferences', function (Request $request) {
        $input = $request->validate([
            'preferences' => ['required', 'array', 'max:200'],
            'preferences.*.notification_type' => ['required', 'string', Rule::in(array_keys(NotificationCatalog::TYPES))],
            'preferences.*.channel' => ['required', Rule::in(NotificationCatalog::CHANNELS)],
            'preferences.*.is_enabled' => ['required', 'boolean'],
        ]);

        foreach ($input['preferences'] as $item) {
            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $request->user()->id, 'notification_type' => $item['notification_type'], 'channel' => $item['channel']],
                ['is_enabled' => (bool) $item['is_enabled']],
            );
        }

        return response()->noContent();
    })->name('me.notification-preferences.update');
});
