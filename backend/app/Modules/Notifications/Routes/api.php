<?php

use App\Modules\Identity\Authorization\AccessResolver;
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

    Route::get('me/notification-preferences', function (Request $request, AccessResolver $access) {
        $chosen = NotificationPreference::query()->where('user_id', $request->user()->id)
            ->get(['notification_type', 'channel', 'is_enabled'])->mapWithKeys(fn ($row) => [$row->notification_type.'|'.$row->channel => (bool) $row->is_enabled]);
        // Stock and capacity alerts are emailed by default to whoever manages limits or zones on a site; the others
        // read them in the application unless they switch the email on here.
        $manager = collect(NotificationCatalog::STOCK_MANAGERS)->contains(fn (string $permission) => $access->siteIdsFor($request->user(), $permission) !== []);

        return response()->json(['data' => collect(NotificationCatalog::TYPES)->map(function (array $definition, string $type) use ($chosen, $manager) {
            $byDefault = $definition['mail'] && (NotificationCatalog::mailDefaultPermissions($type) === null || $manager);

            return [
                'notification_type' => $type,
                'database' => $chosen[$type.'|database'] ?? true,
                'mail' => $definition['mail'] && ($chosen[$type.'|mail'] ?? $byDefault),
                'mail_available' => $definition['mail'],
                // Whether the email goes without asking for it: false for a stock alert to someone who does not manage the stock.
                'mail_by_default' => $byDefault,
            ];
        })->values()]);
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
