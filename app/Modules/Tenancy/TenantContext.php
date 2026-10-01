<?php

namespace App\Modules\Tenancy;

use App\Modules\Tenancy\Enums\CompanyType;
use App\Modules\Tenancy\Exceptions\MissingTenantContextException;
use App\Modules\Tenancy\Models\Company;
use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * Current tenant for the request/job (scoped singleton, reset between requests and jobs).
 * Three modes: tenant (filtered), system (explicit, logged bypass), none (tenant models throw).
 */
final class TenantContext
{
    private ?int $companyId = null;

    private ?CompanyType $companyType = null;

    private bool $system = false;

    public function set(Company $company): void
    {
        $this->companyId = $company->id;
        $this->companyType = $company->company_type;
        $this->system = false;
        Context::addHidden('company_id', $company->id);
    }

    public function clear(): void
    {
        $this->companyId = null;
        $this->companyType = null;
        $this->system = false;
        Context::forgetHidden('company_id');
    }

    public function hasTenant(): bool
    {
        return $this->companyId !== null;
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    public function companyId(): int
    {
        return $this->companyId ?? throw MissingTenantContextException::for('current company');
    }

    public function companyType(): ?CompanyType
    {
        return $this->companyType;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAs(Company $company, Closure $callback): mixed
    {
        return $this->restoring(function () use ($company, $callback) {
            $this->set($company);

            return $callback();
        });
    }

    /**
     * Unfiltered access for allowlisted platform code only. Every entry is logged.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAsSystem(string $reason, Closure $callback): mixed
    {
        Log::info('tenancy.system_mode', ['reason' => $reason]);

        return $this->restoring(function () use ($callback) {
            $this->companyId = null;
            $this->companyType = null;
            $this->system = true;

            return $callback();
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function restoring(Closure $callback): mixed
    {
        $previous = [$this->companyId, $this->companyType, $this->system];

        try {
            return $callback();
        } finally {
            [$this->companyId, $this->companyType, $this->system] = $previous;
        }
    }
}
