<?php

namespace App\Modules\Production\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Sites\Actions\EvaluateStockThresholds;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Support\CompanyModules;
use App\Modules\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * G-03 / PRD-05: an ERP that publishes an export at an address is read every night, with the columns chosen the last
 * time a file of that kind was imported by hand. Nobody re-imports a file; the result of each night is kept beside
 * the address and a failure waits on the dashboard of whoever manages production.
 *
 * The address is fetched by the platform, so it must lead to a public HTTPS server: the host is resolved first, every
 * address it resolves to must be public, and the connection is pinned to it (no redirect is followed). The address is
 * stored encrypted: it often carries the ERP's access key.
 */
final class ErpFeeds
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** @var (Closure(string): list<string>)|null resolves a host name to its addresses; replaced in tests */
    public static ?Closure $resolver = null;

    public function __construct(private readonly ImportEngine $engine, private readonly TenantContext $context) {}

    /**
     * The feeds of a company, as the interface shows them: the host only, never the address itself.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function of(Company $company): array
    {
        $feeds = [];
        foreach ($company->settings['erp_feeds'] ?? [] as $target => $feed) {
            $feeds[$target] = ['target' => $target, 'host' => $feed['host'] ?? null, 'enabled' => (bool) ($feed['enabled'] ?? false),
                'last_run_at' => $feed['last_run_at'] ?? null, 'last_result' => $feed['last_result'] ?? null, 'last_error' => $feed['last_error'] ?? null];
        }

        return $feeds;
    }

    /** Keeps the address of a feed (null: keeps the one already given) and whether it runs every night. */
    public static function set(Company $company, string $target, ?string $url, bool $enabled, User $by): void
    {
        $feeds = $company->settings['erp_feeds'] ?? [];
        $feed = $feeds[$target] ?? [];
        if ($url !== null) {
            $feed = ['url' => Crypt::encryptString($url), 'host' => parse_url($url, PHP_URL_HOST)] + $feed;
        }
        if (! isset($feed['url'])) {
            throw new RuntimeException('NO_URL');
        }
        $feeds[$target] = ['enabled' => $enabled, 'configured_by' => $by->id] + $feed;
        $company->forceFill(['settings' => ['erp_feeds' => $feeds] + ($company->settings ?? [])])->save();
    }

    public static function forget(Company $company, string $target): void
    {
        $feeds = $company->settings['erp_feeds'] ?? [];
        unset($feeds[$target]);
        $company->forceFill(['settings' => ['erp_feeds' => $feeds] + ($company->settings ?? [])])->save();
    }

    /** Every feed switched on, in every active company that uses production. @return int feeds read */
    public function pullAll(): int
    {
        $pulled = 0;
        foreach (Company::query()->where('status', 'active')->whereNotNull('settings->erp_feeds')->get() as $company) {
            if (! CompanyModules::of($company)['production']) {
                continue;
            }
            foreach ($company->settings['erp_feeds'] ?? [] as $target => $feed) {
                if ($feed['enabled'] ?? false) {
                    $this->pull($company, $target);
                    $pulled++;
                }
            }
        }

        return $pulled;
    }

    /**
     * Reads one feed now and imports it. The result (or why nothing could be read) is kept on the feed.
     *
     * @return array<string, mixed> the feed, as of() shows it
     */
    public function pull(Company $company, string $target): array
    {
        $feed = $company->settings['erp_feeds'][$target] ?? null;
        abort_if($feed === null || ! isset(ImportEngine::TARGETS[$target]), 404);
        $result = null;
        $error = null;
        try {
            // The import is done with the rights of whoever set the feed up, as long as they still have them.
            $user = User::query()->find($feed['configured_by'] ?? null);
            $money = in_array($target, ImportEngine::MONEY_TARGETS, true) || array_intersect(array_keys($company->settings['import_mappings'][$target] ?? []), ImportEngine::MONEY_FIELDS) !== [];
            $allowed = $user !== null && $this->context->runAs($company, fn () => ImportEngine::allowed($user, $target) && (! $money || $user->can('production.finance.view')));
            if (! $allowed) {
                throw new RuntimeException('rights');
            }
            $file = $this->download(Crypt::decryptString($feed['url']));
            try {
                $result = $this->context->runAs($company, fn () => $this->import($company, $target, $file, $user));
            } finally {
                @unlink($file->getRealPath());
            }
        } catch (RuntimeException $exception) {
            $error = in_array($exception->getMessage(), ['address', 'download', 'too_large', 'unreadable', 'columns', 'rights'], true) ? $exception->getMessage() : 'unreadable';
        } catch (Throwable) {
            $error = 'download';
        }

        $company->refresh();
        $feeds = $company->settings['erp_feeds'] ?? [];
        if (isset($feeds[$target])) {
            $feeds[$target] = ['last_run_at' => now()->toIso8601String(), 'last_error' => $error,
                'last_result' => $result === null ? ($feeds[$target]['last_result'] ?? null) : [
                    'total' => $result['total'], 'created' => $result['created'], 'updated' => $result['updated'], 'unchanged' => $result['unchanged'],
                    'errors' => count($result['errors']), 'first_errors' => array_slice($result['errors'], 0, 5),
                ]] + $feeds[$target];
            $company->forceFill(['settings' => ['erp_feeds' => $feeds] + ($company->settings ?? [])])->save();
        }

        return self::of($company)[$target];
    }

    /** @return array<string, mixed> */
    private function import(Company $company, string $target, UploadedFile $file, User $user): array
    {
        try {
            $sheet = $this->engine->read($file);
        } catch (Throwable) {
            throw new RuntimeException('unreadable');
        }
        // The columns chosen by hand last time, found again by their heading; otherwise the usual guess.
        $saved = $company->settings['import_mappings'][$target] ?? [];
        $guess = $this->engine->suggest($target, $sheet['headers']);
        $mapping = [];
        foreach (ImportEngine::TARGETS[$target] as $field => [, $required]) {
            $heading = $saved[$field] ?? null;
            $index = $heading === null ? false : array_search(mb_strtolower(trim((string) $heading)), array_map(fn ($cell) => mb_strtolower($cell), $sheet['headers']), true);
            $mapping[$field] = $index !== false ? (int) $index : ($saved === [] || ! array_key_exists($field, $saved) ? $guess[$field] : null);
            if ($required && $mapping[$field] === null) {
                throw new RuntimeException('columns');
            }
        }
        $result = $this->engine->run($target, $sheet['rows'], $mapping, false, $user, 'api');
        ProductionVolumes::sync($company->id);
        foreach ($this->engine->sitesTouched() as $siteId) {
            app(EvaluateStockThresholds::class)->forSite($siteId);
        }

        return $result;
    }

    /**
     * The addresses of the server of an export, all of them public; refused otherwise ("address").
     *
     * @return list<string>
     */
    public static function publicAddresses(string $url): array
    {
        $parts = parse_url($url) ?: [];
        $host = $parts['host'] ?? '';
        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user'])) {
            throw new RuntimeException('address');
        }
        $addresses = (self::$resolver ?? fn (string $name) => filter_var($name, FILTER_VALIDATE_IP) ? [$name] : (gethostbynamel($name) ?: []))($host);
        if ($addresses === []) {
            throw new RuntimeException('download');
        }
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException('address');
            }
        }

        return array_values($addresses);
    }

    /** Downloads the export into a temporary file, from a public HTTPS server only. */
    private function download(string $url): UploadedFile
    {
        $addresses = self::publicAddresses($url);
        $parts = parse_url($url);
        $host = $parts['host'];
        $port = $parts['port'] ?? 443;
        try {
            $response = Http::timeout(30)->connectTimeout(10)->withoutRedirecting()
                ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$addresses[0]}"], CURLOPT_MAXFILESIZE => self::MAX_BYTES]])->get($url);
        } catch (Throwable) {
            throw new RuntimeException('download');
        }
        if (! $response->successful()) {
            throw new RuntimeException('download');
        }
        $body = $response->body();
        if (strlen($body) > self::MAX_BYTES) {
            throw new RuntimeException('too_large');
        }
        $type = (string) $response->header('Content-Type');
        $extension = match (true) {
            str_contains($type, 'spreadsheetml') || str_ends_with(strtolower($parts['path'] ?? ''), '.xlsx') => 'xlsx',
            str_contains($type, 'ms-excel') || str_ends_with(strtolower($parts['path'] ?? ''), '.xls') => 'xls',
            default => 'csv',
        };
        $path = tempnam(sys_get_temp_dir(), 'erp');
        file_put_contents($path, $body);

        return new UploadedFile($path, "feed.{$extension}", null, null, true);
    }
}
