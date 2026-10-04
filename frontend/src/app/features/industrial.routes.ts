import { Routes } from '@angular/router';

import { permissionGuard } from '../core/auth/guards';

/** Workspace of an industrial company. Each screen is guarded by the permission the API will also require. */
export const INDUSTRIAL_ROUTES: Routes = [
  { path: 'dashboard', canActivate: [permissionGuard('dashboard.view')], loadComponent: () => import('./dashboard/dashboard').then((m) => m.DashboardPage), title: 'Tableau de bord — DivaWaste' },
  { path: 'onboarding', loadComponent: () => import('./account/account').then((m) => m.OnboardingPage), title: 'Mise en route — DivaWaste' },
  { path: 'lots', canActivate: [permissionGuard('lots.view')], loadComponent: () => import('./lots/lots').then((m) => m.LotsPage), title: 'Lots — DivaWaste' },
  { path: 'lots/:ulid', canActivate: [permissionGuard('lots.view')], loadComponent: () => import('./lots/lot-detail').then((m) => m.LotDetailPage), title: 'Lot — DivaWaste' },
  { path: 'stock', canActivate: [permissionGuard('stock.view')], loadComponent: () => import('./stock/stock').then((m) => m.StockPage), title: 'Stock — DivaWaste' },
  { path: 'pickups', canActivate: [permissionGuard('pickups.view')], loadComponent: () => import('./pickups/pickups').then((m) => m.PickupsPage), title: 'Enlèvements — DivaWaste' },
  { path: 'pickups/new', canActivate: [permissionGuard('pickups.request')], loadComponent: () => import('./pickups/pickup-create').then((m) => m.PickupCreatePage), title: 'Nouvelle demande — DivaWaste' },
  { path: 'pickups/:ulid', canActivate: [permissionGuard('pickups.view')], loadComponent: () => import('./pickups/pickup-detail').then((m) => m.PickupDetailPage), title: 'Enlèvement — DivaWaste' },
  { path: 'providers', canActivate: [permissionGuard('providers.view')], loadComponent: () => import('./providers/providers').then((m) => m.ProvidersPage), title: 'Prestataires — DivaWaste' },
  { path: 'compliance/register', canActivate: [permissionGuard('documents.view')], loadComponent: () => import('./compliance/compliance').then((m) => m.RegisterPage), title: 'Registre — DivaWaste' },
  { path: 'compliance/manifests', canActivate: [permissionGuard('documents.view')], loadComponent: () => import('./compliance/compliance').then((m) => m.ManifestsPage), title: 'Bordereaux — DivaWaste' },
  { path: 'compliance/declarations', canActivate: [permissionGuard('declarations.prepare')], loadComponent: () => import('./compliance/compliance').then((m) => m.DeclarationsPage), title: 'Déclarations — DivaWaste' },
  { path: 'compliance/documents', canActivate: [permissionGuard('documents.view')], loadComponent: () => import('./compliance/compliance').then((m) => m.DocumentsPage), title: 'Documents — DivaWaste' },
  { path: 'reports', canActivate: [permissionGuard('reports.view')], loadComponent: () => import('./reports/reports').then((m) => m.ReportsPage), title: 'Rapports — DivaWaste' },
  { path: 'sites', canActivate: [permissionGuard('sites.view')], loadComponent: () => import('./sites/sites').then((m) => m.SitesPage), title: 'Sites — DivaWaste' },
  { path: 'sites/:ulid', canActivate: [permissionGuard('sites.view')], loadComponent: () => import('./sites/sites').then((m) => m.SiteDetailPage), title: 'Site — DivaWaste' },
  { path: 'waste-types', canActivate: [permissionGuard('waste_types.view')], loadComponent: () => import('./catalog/waste-types').then((m) => m.WasteTypesPage), title: 'Types de déchets — DivaWaste' },
  { path: 'admin/subscription', canActivate: [permissionGuard('subscription.view')], loadComponent: () => import('./admin/admin').then((m) => m.SubscriptionPage), title: 'Abonnement — DivaWaste' },
  { path: 'admin/audit', canActivate: [permissionGuard('audit.view')], loadComponent: () => import('./admin/admin').then((m) => m.AuditPage), title: "Journal d'audit — DivaWaste" },
  // Notifications emitted by the API point here.
  { path: 'subscription', redirectTo: 'admin/subscription' },
];

/** Workspace of a provider: the requests addressed to it, its documents, its public profile and accreditations. */
export const PROVIDER_ROUTES: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'pickups' },
  { path: 'pickups', canActivate: [permissionGuard('provider.pickups.view')], loadComponent: () => import('./pickups/pickups').then((m) => m.PickupsPage), title: 'Demandes — DivaWaste' },
  { path: 'pickups/:ulid', canActivate: [permissionGuard('provider.pickups.view')], loadComponent: () => import('./pickups/pickup-detail').then((m) => m.PickupDetailPage), title: 'Demande — DivaWaste' },
  { path: 'manifests', canActivate: [permissionGuard('provider.pickups.view')], loadComponent: () => import('./compliance/compliance').then((m) => m.ManifestsPage), title: 'Bordereaux — DivaWaste' },
  { path: 'documents', canActivate: [permissionGuard('provider.pickups.view')], loadComponent: () => import('./compliance/compliance').then((m) => m.DocumentsPage), title: 'Documents — DivaWaste' },
  { path: 'profile', canActivate: [permissionGuard('provider.profile.manage')], loadComponent: () => import('./provider/provider').then((m) => m.ProviderProfilePage), title: 'Profil — DivaWaste' },
  { path: 'accreditations', canActivate: [permissionGuard('provider.accreditations.manage')], loadComponent: () => import('./provider/provider').then((m) => m.ProviderAccreditationsPage), title: 'Agréments — DivaWaste' },
];

/** Back-office of Diva Software staff. */
export const PLATFORM_ROUTES: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'companies' },
  { path: 'companies', canActivate: [permissionGuard('platform.companies.review')], loadComponent: () => import('./platform/platform').then((m) => m.PlatformCompaniesPage), title: 'Sociétés — DivaWaste' },
  { path: 'companies/:ulid', canActivate: [permissionGuard('platform.companies.review')], loadComponent: () => import('./platform/platform').then((m) => m.PlatformCompanyPage), title: 'Société — DivaWaste' },
  { path: 'accreditations', canActivate: [permissionGuard('platform.providers.review')], loadComponent: () => import('./platform/platform').then((m) => m.PlatformAccreditationsPage), title: 'Agréments — DivaWaste' },
  { path: 'payments', canActivate: [permissionGuard('platform.payments.validate')], loadComponent: () => import('./platform/platform').then((m) => m.PlatformPaymentsPage), title: 'Virements — DivaWaste' },
  { path: 'catalog', canActivate: [permissionGuard('platform.catalog.manage')], loadComponent: () => import('./platform/platform').then((m) => m.PlatformCatalogPage), title: 'Catalogue — DivaWaste' },
  { path: 'roles', canActivate: [permissionGuard('platform.roles.manage')], loadComponent: () => import('./platform/platform').then((m) => m.PlatformRolesPage), title: 'Rôles — DivaWaste' },
];

/** Screens shared by industrial and provider accounts: the team and the company itself. */
export const TENANT_ROUTES: Routes = [
  { path: 'admin/users', canActivate: [permissionGuard('users.view')], loadComponent: () => import('./admin/users').then((m) => m.UsersPage), title: 'Utilisateurs — DivaWaste' },
  { path: 'admin/company', canActivate: [permissionGuard('company.update')], loadComponent: () => import('./admin/admin').then((m) => m.CompanyPage), title: 'Société — DivaWaste' },
  { path: 'account/notifications', loadComponent: () => import('./account/account').then((m) => m.NotificationsPage), title: 'Notifications — DivaWaste' },
];

export const ACCOUNT_ROUTES: Routes = [
  { path: 'account/profile', loadComponent: () => import('./account/account').then((m) => m.ProfilePage), title: 'Mon profil — DivaWaste' },
  { path: 'account/security', loadComponent: () => import('./account/account').then((m) => m.SecurityPage), title: 'Sécurité — DivaWaste' },
];
