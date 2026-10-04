<?php

use App\Modules\Billing\Actions\ManageSubscription;
use App\Modules\Identity\Models\User;
use App\Modules\Providers\Actions\AlertExpiringAccreditations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

/*
 * Scheduled tasks (cahier §5: Laravel Scheduler). Each one is also a command so it can be run by hand.
 * `withoutOverlapping` + `onOneServer` keep a task single even with several application servers.
 */
Artisan::command('accreditations:alert', function (AlertExpiringAccreditations $action) {
    $result = $action->handle();
    $this->info("Accreditations: {$result['expiring']} expiring, {$result['expired']} expired.");
})->purpose('M4-02: alert providers and their partners 30 days before an accreditation expires, and at expiry');

Schedule::command('accreditations:alert')->dailyAt('06:00')->withoutOverlapping()->onOneServer();

Artisan::command('subscriptions:run', function (ManageSubscription $subscriptions) {
    $this->info('Subscriptions: '.json_encode($subscriptions->runDaily()));
})->purpose('M7-04: invoice the periods that ended, apply cancellations, warn before a trial ends and expire it after');

Schedule::command('subscriptions:run')->dailyAt('05:00')->withoutOverlapping()->onOneServer();

/*
 * First platform administrator (Diva Software staff). Not exposed through the API on purpose.
 */
Artisan::command('platform:create-admin {email} {--first-name=Admin} {--last-name=Plateforme}', function () {
    $password = Str::password(20);
    $user = User::query()->create([
        'first_name' => $this->option('first-name'), 'last_name' => $this->option('last-name'),
        'email' => $this->argument('email'), 'password' => $password,
    ]);
    $user->forceFill(['email_verified_at' => now()])->save();
    $user->assignRole('super_admin');

    $this->info("Platform administrator created: {$user->email}");
    $this->warn("Temporary password (shown once): {$password}");
})->purpose('Create a platform super-administrator');
