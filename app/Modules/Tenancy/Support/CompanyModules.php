<?php

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Query\Builder;

/**
 * The parts of DivaWaste a company uses, and how it works (with an ERP or not). They only shape the interface:
 * a module that is off hides its menu, its fields in the other forms and its help; rights are still checked by
 * permissions on every request. A company that never chose keeps every module, as before modules existed.
 */
final class CompanyModules
{
    /** production: OF, orders, materials; production_finance: costs and revenue; authorizations: customs exit permits. */
    public const MODULES = ['production', 'production_finance', 'marketplace', 'contracts', 'authorizations', 'colors', 'workshop'];

    /** Answers of the first onboarding step. */
    public const PROFILE = ['erp', 'production', 'hazardous', 'customs', 'marketplace'];

    /** What the company makes: its zone types, waste families and catalogue follow it. */
    public const INDUSTRIES = ['textile', 'agriculture', 'food', 'metal', 'plastics', 'chemicals', 'construction', 'other'];

    /**
     * The company's industry; a company that never said keeps textile, what DivaWaste was first made for.
     */
    public static function industry(Company $company): string
    {
        $industry = $company->settings['industry'] ?? null;

        return in_array($industry, self::INDUSTRIES, true) ? $industry : 'textile';
    }

    public static function setIndustry(Company $company, string $industry): void
    {
        $company->forceFill(['settings' => ['industry' => $industry] + ($company->settings ?? [])])->save();
    }

    /**
     * Rows of a reference table that serve this industry (those that name none serve every industry).
     *
     * @param  Builder  $query
     */
    public static function forIndustry($query, string $industry, string $column = 'industries')
    {
        return $query->where(fn ($q) => $q->whereNull($column)->orWhereJsonContains($column, $industry));
    }

    /**
     * @return array<string, bool>
     */
    public static function of(Company $company): array
    {
        $stored = $company->settings['modules'] ?? [];

        return array_combine(self::MODULES, array_map(fn (string $module) => (bool) ($stored[$module] ?? true), self::MODULES));
    }

    /**
     * @return array<string, bool>|null null until the company answered
     */
    public static function profile(Company $company): ?array
    {
        $profile = $company->settings['profile'] ?? null;

        return $profile === null ? null : array_combine(self::PROFILE, array_map(fn (string $key) => (bool) ($profile[$key] ?? false), self::PROFILE));
    }

    /**
     * @param  array<string, bool>  $modules
     */
    public static function set(Company $company, array $modules): void
    {
        $merged = array_intersect_key($modules, array_flip(self::MODULES)) + self::of($company);
        // Costs and revenue are computed from production data: they cannot be on without it.
        if (! $merged['production']) {
            $merged['production_finance'] = false;
        }
        $company->forceFill(['settings' => ['modules' => array_map('boolval', $merged)] + ($company->settings ?? [])])->save();
    }

    /**
     * The answers decide the modules: production (and its costs) when production is followed, the customs permits
     * when the company exports under authorisation, the bourse when it wants to sell lots. Contracts, colours and
     * the workshop application stay on: they cost nothing to someone who does not open them.
     *
     * @param  array<string, bool>  $answers
     */
    public static function answer(Company $company, array $answers): void
    {
        $profile = array_combine(self::PROFILE, array_map(fn (string $key) => (bool) ($answers[$key] ?? false), self::PROFILE));
        $company->forceFill(['settings' => ['profile' => $profile] + ($company->settings ?? [])])->save();
        self::set($company, [
            'production' => $profile['production'] || $profile['erp'],
            'production_finance' => $profile['production'] || $profile['erp'],
            'authorizations' => $profile['customs'],
            'marketplace' => $profile['marketplace'],
        ]);
    }
}
