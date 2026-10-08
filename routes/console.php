<?php

use App\Modules\Billing\Actions\ManageSubscription;
use App\Modules\Common\Docs\ApiRoutes;
use App\Modules\Common\Docs\DemoData;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Actions\NotifyUsers;
use App\Modules\Notifications\Support\EmailLog;
use App\Modules\Providers\Actions\AlertExpiringAccreditations;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Knuckles\Scribe\Scribe;

/*
 * Scheduled tasks (cahier §5: Laravel Scheduler). Each one is also a command so it can be run by hand.
 * `withoutOverlapping` + `onOneServer` keep a task single even with several application servers.
 */
Artisan::command('accreditations:alert', function (AlertExpiringAccreditations $action) {
    $result = $action->handle();
    $this->info("Accreditations: {$result['expiring']} expiring, {$result['expired']} expired.");
})->purpose('M4-02: alert providers and their partners 30 days before an accreditation expires, and at expiry');

Schedule::command('accreditations:alert')->dailyAt('06:00')->withoutOverlapping()->onOneServer();

Artisan::command('authorizations:alert', function (NotifyUsers $notify) {
    $today = now()->startOfDay();
    $sent = 0;
    foreach ([30 => 'notified_30d_at', 7 => 'notified_7d_at'] as $days => $flag) {
        $rows = DB::table('waste_authorizations as a')->join('waste_types as t', 't.id', '=', 'a.waste_type_id')
            ->where('a.is_active', true)->whereNull("a.{$flag}")->whereDate('a.valid_until', '>=', $today)->whereDate('a.valid_until', '<=', $today->copy()->addDays($days))
            // Nothing to say when a later authorisation for the same waste already takes over.
            ->whereNotExists(fn ($query) => $query->from('waste_authorizations as newer')->whereColumn('newer.company_id', 'a.company_id')
                ->whereColumn('newer.waste_type_id', 'a.waste_type_id')->where('newer.is_active', true)->whereColumn('newer.valid_until', '>', 'a.valid_until'))
            ->get(['a.id', 'a.company_id', 'a.reference', 'a.valid_until', 't.name']);
        foreach ($rows as $row) {
            $until = Carbon::parse($row->valid_until);
            $notify->toCompanyPermission((int) $row->company_id, 'pickups.request', 'authorization.expiring_soon', [
                'reference' => $row->reference, 'waste_type' => $row->name, 'date' => $until->format('d/m/Y'), 'days' => max(0, (int) $today->diffInDays($until, false)),
            ], '/compliance/authorizations');
            // Flag set per row, after its notification: a crash in the middle never loses an alert.
            DB::table('waste_authorizations')->where('id', $row->id)->update([$flag => now()]);
            $sent++;
        }
    }
    $this->info("Authorisations: {$sent} alert(s).");
})->purpose('Alert 30 and 7 days before a waste authorisation ends, unless a later one already takes over');

Schedule::command('authorizations:alert')->dailyAt('06:10')->withoutOverlapping()->onOneServer();

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
    // The emails kept for reading belonged to the previous dataset.
    File::deleteDirectory(EmailLog::directory());

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

/*
 * Emails (docs/DEVELOPER_RUNBOOK.md §Emails). Every email the application produces is traced in notification_logs;
 * these two commands show that trace and prove whether the configured mail server accepts a message.
 */
Artisan::command('mail:outbox {--limit=15 : Number of emails listed} {--to= : Only this recipient} {--show= : Print one email (its number in the list)}', function () {
    $transport = (string) config('mail.mailers.'.config('mail.default').'.transport', config('mail.default'));
    $local = in_array($transport, EmailLog::LOCAL_TRANSPORTS, true);
    $this->line('Mail transport: <options=bold>'.$transport.'</>'.($local
        ? ' — emails are generated but NOT delivered to any mailbox. Read them here.'
        : ' ('.config('mail.mailers.'.config('mail.default').'.host').') — emails are handed to that server.'));

    $state = fn (stdClass $row) => match (true) {
        $row->status === 'failed' => 'FAILED',
        in_array($row->transport, EmailLog::LOCAL_TRANSPORTS, true) => 'generated, not delivered',
        default => 'accepted by '.$row->transport,
    };
    $link = function (int $id): ?string {
        $html = is_file(EmailLog::bodyPath($id)) ? (string) file_get_contents(EmailLog::bodyPath($id)) : '';

        return preg_match('~href="('.preg_quote(rtrim((string) config('app.frontend_url'), '/'), '~').'/[^"]+)"~', $html, $match) ? html_entity_decode($match[1]) : null;
    };

    if ($this->option('show') !== null) {
        $row = DB::table('notification_logs')->where('channel', 'mail')->where('id', (int) $this->option('show'))->first();
        if ($row === null) {
            $this->error('No email with this number.');

            return 1;
        }
        $this->table([], [['To', $row->recipient], ['Subject', $row->subject], ['Type', $row->notification_type], ['Transport', $row->transport],
            ['Status', $state($row).($row->error_message ? ' — '.$row->error_message : '')], ['Date (UTC)', $row->created_at], ['Link', $link((int) $row->id) ?? '—'],
            ['HTML file', is_file(EmailLog::bodyPath((int) $row->id)) ? EmailLog::bodyPath((int) $row->id) : '(not kept)']]);
        if (is_file(EmailLog::bodyPath((int) $row->id))) {
            $this->line(trim((string) preg_replace('/\n\s*\n+/', "\n", html_entity_decode(strip_tags((string) preg_replace('~<(style|head)\b.*?</\1>~is', '', (string) file_get_contents(EmailLog::bodyPath((int) $row->id))))))));
        }

        return 0;
    }

    $rows = DB::table('notification_logs')->where('channel', 'mail')
        ->when($this->option('to'), fn ($query, $to) => $query->where('recipient', 'like', '%'.$to.'%'))
        ->orderByDesc('id')->limit(max(1, (int) $this->option('limit')))->get();
    if ($rows->isEmpty()) {
        $this->line('No email produced yet.');

        return 0;
    }

    $this->table(['#', 'Date (UTC)', 'To', 'Subject', 'Status'], $rows->map(fn (stdClass $row) => [$row->id, $row->created_at, $row->recipient, Str::limit((string) $row->subject, 60), $state($row)])->all());
    foreach ($rows as $row) {
        if ($row->status === 'failed') {
            $this->warn("#{$row->id} failed: {$row->error_message}");
        } elseif (($url = $link((int) $row->id)) !== null) {
            $this->line("#{$row->id} {$url}");
        }
    }

    return 0;
})->purpose('List the emails the application produced: recipient, subject, link, and whether they left the server');

Artisan::command('mail:test {email : Address that should receive the test message}', function () {
    $transport = (string) config('mail.mailers.'.config('mail.default').'.transport', config('mail.default'));
    $from = config('mail.from.address');
    try {
        Mail::raw("Ceci est un message de test envoyé par DivaWaste.\nS'il arrive dans votre boîte, l'envoi d'emails est correctement configuré.", fn ($message) => $message
            ->to($this->argument('email'))->subject('DivaWaste — test d\'envoi d\'email'));
    } catch (Throwable $error) {
        $this->error("The mail server refused the message (transport {$transport}): ".$error->getMessage());

        return 1;
    }

    if (in_array($transport, EmailLog::LOCAL_TRANSPORTS, true)) {
        $this->warn("Transport \"{$transport}\": the message was only written locally. Nothing was sent to {$this->argument('email')}.");
        $this->line('Set MAIL_MAILER=smtp and the MAIL_* values of a real mail server to send emails (docs/DEVELOPER_RUNBOOK.md).');

        return 0;
    }

    $this->info("Accepted by the {$transport} server ".config('mail.mailers.'.config('mail.default').'.host')." from {$from}.");
    $this->line("Now check the inbox of {$this->argument('email')}, and its spam folder: acceptance by the server is not delivery to the mailbox.");

    return 0;
})->purpose('Send one test email through the configured mail server and report what the server answered');

/*
 * API documentation (cahier §5). Generated from the route definitions by Scribe (development dependency), then
 * checked against the routes the application really exposes. docs/DEVELOPER_RUNBOOK.md §API documentation.
 */
Artisan::command('api:docs', function () {
    if (! class_exists(Scribe::class)) {
        $this->error('Scribe is not installed (development dependency): run "composer install" without --no-dev.');

        return 1;
    }

    $examples = DemoData::available();
    $this->line($examples
        ? 'Demo dataset found: read routes are called for real example answers (each call is rolled back).'
        : 'No demo dataset in this database: routes, parameters and errors are documented, example answers are not. Use --env=demo for them.');
    $this->callSilently('scribe:generate', ['--no-interaction' => true]);

    $generated = storage_path('app/private/scribe');
    if (! is_file("{$generated}/openapi.yaml")) {
        $this->error('Scribe did not produce an OpenAPI file. Run "php artisan scribe:generate -v" to see why.');

        return 1;
    }
    File::ensureDirectoryExists(base_path('docs/api'));
    File::copy("{$generated}/openapi.yaml", base_path('docs/api/openapi.yaml'));
    File::copy("{$generated}/collection.json", base_path('docs/api/postman.json'));

    // The proof that the documentation is the API: every route is in it, and nothing else.
    $documented = ApiRoutes::documented(base_path('docs/api/openapi.yaml'));
    $missing = array_diff(ApiRoutes::exposed(), $documented);
    $unknown = array_diff($documented, ApiRoutes::exposed());
    foreach ($missing as $route) {
        $this->error("Not documented: {$route}");
    }
    foreach ($unknown as $route) {
        $this->error("Documented but not routed: {$route}");
    }

    $this->info(count($documented).' operations documented for '.count(ApiRoutes::exposed()).' API routes.');
    $this->line('OpenAPI 3: docs/api/openapi.yaml — Postman: docs/api/postman.json — HTML: '.rtrim((string) config('app.url'), '/').'/docs (resources/views/scribe).');

    return $missing === [] && $unknown === [] ? 0 : 1;
})->purpose('Generate the API documentation (OpenAPI, Postman, HTML) from the routes and check it covers every route');
