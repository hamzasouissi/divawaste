<?php

namespace App\Modules\Tenancy\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The ULID must identify a row of the CURRENT tenant (goes through the model's TenantScope).
 * Never use Rule::exists() on tenant tables (blueprint §3.9).
 */
final class TenantExists implements ValidationRule
{
    /**
     * @param  class-string<Model>  $model
     * @param  (Closure(Builder<Model>): mixed)|null  $constraint  extra filter (e.g. zone of a given site)
     */
    public function __construct(private readonly string $model, private readonly ?Closure $constraint = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $query = $this->model::query()->where('ulid', is_string($value) ? strtoupper($value) : '');

        if ($this->constraint !== null) {
            ($this->constraint)($query);
        }

        if (! $query->exists()) {
            $fail('validation.exists')->translate();
        }
    }
}
