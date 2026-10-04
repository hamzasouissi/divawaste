<?php

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Requests\SetNotificationPreferencesRequest;
use App\Modules\Notifications\Resources\NotificationPreferenceResource;
use App\Modules\Notifications\Resources\NotificationResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('me/notifications', function (Request $request) {
        $user = $request->user();

        return NotificationResource::collection(Notification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->orderByDesc('created_at')
            ->cursorPaginate(20));
    })->name('me.notifications.index');

    Route::post('me/notifications/{notification}/read', function (Request $request, Notification $notification) {
        abort_unless($notification->notifiable_id === $request->user()->id, 403);

        $notification->markAsRead();

        return new NotificationResource($notification->fresh());
    })->name('me.notifications.read');

    Route::put('me/notification-preferences', function (SetNotificationPreferencesRequest $request) {
        $items = [];

        foreach ($request->preferences() as $item) {
            $items[] = NotificationPreference::query()->updateOrCreate(
                [
                    'company_id' => app(\App\Modules\Tenancy\TenantContext::class)->companyId(),
                    'user_id' => $request->user()->id,
                    'notification_type' => $item['notification_type'],
                    'channel' => $item['channel'],
                ],
                ['is_enabled' => (bool) $item['is_enabled']],
            );
        }

        return NotificationPreferenceResource::collection($items);
    })->name('me.notification-preferences.update');
});
