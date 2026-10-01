<?php

namespace App\Modules\Identity\Authorization;

use App\Modules\Identity\Enums\Audience;
use App\Modules\Identity\Enums\PermissionScope;

/**
 * Permission catalog (code is the source of truth, seeded into `permissions`) and the default
 * system-role matrix (blueprint §20.2). The matrix stays editable at runtime (cahier §2).
 */
final class PermissionCatalog
{
    private const C = PermissionScope::Company;

    private const S = PermissionScope::Site;

    private const P = PermissionScope::Platform;

    /**
     * @return array<string, array{PermissionScope, Audience}>
     */
    public static function permissions(): array
    {
        $any = Audience::Any;
        $ind = Audience::Industrial;
        $prv = Audience::Provider;
        $plt = Audience::Platform;

        return [
            'app.web.access' => [self::C, $any],
            'app.mobile.access' => [self::C, $any],
            'company.view' => [self::C, $any],
            'company.update' => [self::C, $any],
            'users.view' => [self::C, $any],
            'users.invite' => [self::C, $any],
            'users.manage' => [self::C, $any],
            'roles.manage' => [self::C, $any],
            'audit.view' => [self::C, $any],
            'subscription.view' => [self::C, $any],
            'subscription.manage' => [self::C, $any],
            'invoices.view' => [self::C, $any],
            'payments.declare' => [self::C, $any],
            'sites.view' => [self::S, $ind],
            'sites.manage' => [self::C, $ind],
            'zones.manage' => [self::S, $ind],
            'waste_types.view' => [self::C, $ind],
            'waste_types.manage' => [self::C, $ind],
            'lots.view' => [self::S, $ind],
            'lots.create' => [self::S, $ind],
            'lots.update' => [self::S, $ind],
            'lots.weigh' => [self::S, $ind],
            'lots.move' => [self::S, $ind],
            'lots.split' => [self::S, $ind],
            'lots.group' => [self::S, $ind],
            'lots.print_label' => [self::S, $ind],
            'lots.delete' => [self::S, $ind],
            'stock.view' => [self::S, $ind],
            'stock.thresholds.manage' => [self::S, $ind],
            'pickups.view' => [self::S, $ind],
            'pickups.request' => [self::S, $ind],
            'pickups.cancel' => [self::S, $ind],
            'pickups.load' => [self::S, $ind],
            'pickups.price' => [self::S, $ind],
            'pickups.complete' => [self::S, $ind],
            'pickups.override_eligibility' => [self::S, $ind],
            'providers.view' => [self::C, $ind],
            'providers.partnerships.manage' => [self::C, $ind],
            'documents.view' => [self::S, $ind],
            'documents.generate' => [self::S, $ind],
            'documents.upload' => [self::S, $ind],
            'documents.sign' => [self::S, $ind],
            'documents.delete' => [self::S, $ind],
            'declarations.prepare' => [self::S, $ind],
            'declarations.validate' => [self::S, $ind],
            'dashboard.view' => [self::S, $ind],
            'reports.view' => [self::S, $ind],
            'reports.export' => [self::S, $ind],
            'production_volumes.manage' => [self::S, $ind],
            'sync_conflicts.resolve' => [self::S, $ind],
            'provider.profile.manage' => [self::C, $prv],
            'provider.accreditations.manage' => [self::C, $prv],
            'provider.pickups.view' => [self::C, $prv],
            'provider.pickups.respond' => [self::C, $prv],
            'provider.pickups.receive' => [self::C, $prv],
            'provider.certificates.upload' => [self::C, $prv],
            'provider.manifests.sign' => [self::C, $prv],
            'platform.support.view' => [self::P, $plt],
            'platform.companies.review' => [self::P, $plt],
            'platform.companies.suspend' => [self::P, $plt],
            'platform.providers.review' => [self::P, $plt],
            'platform.catalog.manage' => [self::P, $plt],
            'platform.countries.manage' => [self::P, $plt],
            'platform.plans.manage' => [self::P, $plt],
            'platform.invoices.manage' => [self::P, $plt],
            'platform.payments.validate' => [self::P, $plt],
            'platform.roles.manage' => [self::P, $plt],
            'platform.legal.manage' => [self::P, $plt],
        ];
    }

    /**
     * System roles: name => [audience, permission names].
     *
     * @return array<string, array{Audience, list<string>}>
     */
    public static function systemRoles(): array
    {
        $all = array_keys(self::permissions());
        $of = fn (Audience ...$audiences) => array_values(array_filter(
            $all,
            fn (string $name) => in_array(self::permissions()[$name][1], $audiences, true),
        ));
        $industrial = $of(Audience::Industrial, Audience::Any);
        $billingAndAccess = ['users.invite', 'users.manage', 'roles.manage', 'subscription.manage', 'payments.declare', 'invoices.view', 'subscription.view'];

        return [
            'super_admin' => [Audience::Platform, $of(Audience::Platform)],
            'platform_support' => [Audience::Platform, ['platform.support.view']],
            'client_admin' => [Audience::Industrial, $industrial],
            'environment_manager' => [Audience::Industrial, array_values(array_diff($industrial, $billingAndAccess))],
            'workshop_operator' => [Audience::Industrial, [
                'app.mobile.access', 'lots.view', 'lots.create', 'lots.weigh', 'lots.move', 'lots.print_label',
                'lots.split', 'lots.group', 'stock.view', 'pickups.load',
            ]],
            'management_viewer' => [Audience::Industrial, array_values(array_merge(
                ['app.web.access', 'reports.export'],
                array_filter($industrial, fn (string $name) => str_ends_with($name, '.view')),
            ))],
            'auditor' => [Audience::Industrial, ['app.web.access', 'lots.view', 'documents.view', 'pickups.view', 'reports.view']],
            'provider_admin' => [Audience::Provider, array_values(array_merge(
                ['app.web.access', 'company.view', 'company.update', 'users.view', 'users.invite', 'users.manage'],
                $of(Audience::Provider),
            ))],
            'provider_operator' => [Audience::Provider, [
                'app.web.access', 'provider.pickups.view', 'provider.pickups.receive', 'provider.certificates.upload',
            ]],
        ];
    }
}
