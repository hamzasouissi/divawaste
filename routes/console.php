<?php

use App\Modules\Billing\Actions\ManageSubscription;
use App\Modules\Identity\Models\User;
use App\Modules\Providers\Actions\AlertExpiringAccreditations;
use Database\Seeders\DemoSeeder;
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
 * Local demonstration environment (docs/DEVELOPER_RUNBOOK.md): its own database, rebuilt from scratch with the demo dataset.
 * Refuses to run anywhere else, so it can never empty a real database.
 */
Artisan::command('demo:reset', function () {
    $database = (string) config('database.connections.'.config('database.default').'.database');
    if (! app()->environment('demo') || ! str_contains($database, 'demo')) {
        $this->error("Refused: demo:reset only runs with --env=demo on a database whose name contains \"demo\" (current: {$database}, environment: ".app()->environment().').');

        return 1;
    }

    $connection = config('database.connections.'.config('database.default'));
    $pdo = new PDO("mysql:host={$connection['host']};port={$connection['port']}", $connection['username'], $connection['password']);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $this->info("Rebuilding {$database} (about a minute)…");
    $this->call('migrate:fresh', ['--seed' => true, '--force' => true]);
    $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
    $this->call('cache:clear');

    $this->newLine();
    $this->info('Demo data ready. Password of every account: '.DemoSeeder::PASSWORD);
    $this->table(['Email', 'Role'], [
        ['admin@divawaste.test', 'Platform administrator'], ['amira@capbon.test', 'Client administrator — Textile du Cap Bon'],
        ['sami@capbon.test', 'Environment manager'], ['leila@capbon.test', 'Workshop operator (Nabeul)'],
        ['direction@capbon.test', 'Management, read-only'], ['audit@capbon.test', 'Auditor'],
        ['karim@sahel-recyclage.test', 'Provider administrator — Recyclage du Sahel'], ['nour@monastir-jeans.test', 'Client administrator — Monastir Jeans Confection'],
        ['(others)', 'Pending, suspended, rejected and expired accounts: docs/TEST_ACCOUNTS.md'],
    ]);

    return 0;
})->purpose('Rebuild the local demonstration database with the demo dataset');

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
