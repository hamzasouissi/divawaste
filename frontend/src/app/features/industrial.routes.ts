import { Routes } from '@angular/router';

import { permissionGuard } from '../core/auth/guards';

/** Workspace of an industrial company. Each screen is guarded by the permission the API will also require. */
export const INDUSTRIAL_ROUTES: Routes = [
  { path: 'dashboard', canActivate: [permissionGuard('dashboard.view')], loadComponent: () => import('./dashboard/dashboard').then((m) => m.DashboardPage), title: 'Tableau de bord — DivaWaste' },
  { path: 'davi/train', loadComponent: () => import('./davi/train').then((m) => m.DaviTrainPage), title: 'Entraîner l\'assistant — DivaWaste' },
  { path: 'onboarding', loadComponent: () => import('./account/account').then((m) => m.OnboardingPage), title: 'Mise en route — DivaWaste' },
  { path: 'lots', canActivate: [permissionGuard('lots.view')], loadComponent: () => import('./lots/lots').then((m) => m.LotsPage), title: 'Lots — DivaWaste' },
  { path: 'lots/import', canActivate: [permissionGuard('lots.create')], loadComponent: () => import('./production/import').then((m) => m.ImportPage), data: { lots: true }, title: 'Importer des lots — DivaWaste' },
  { path: 'lots/:ulid', canActivate: [permissionGuard('lots.view')], loadComponent: () => import('./lots/lot-detail').then((m) => m.LotDetailPage), title: 'Lot — DivaWaste' },
  { path: 'stock', canActivate: [permissionGuard('stock.view')], loadComponent: () => import('./stock/stock').then((m) => m.StockPage), title: 'Stock — DivaWaste' },
  { path: 'pickups', canActivate: [permissionGuard('pickups.view')], loadComponent: () => import('./pickups/pickups').then((m) => m.PickupsPage), title: 'Enlèvements — DivaWaste' },
  { path: 'pickups/new', canActivate: [permissionGuard('pickups.request')], loadComponent: () => import('./pickups/pickup-create').then((m) => m.PickupCreatePage), title: 'Nouvelle demande — DivaWaste' },
  { path: 'pickups/:ulid', canActivate: [permissionGuard('pickups.view')], loadComponent: () => import('./pickups/pickup-detail').then((m) => m.PickupDetailPage), title: 'Enlèvement — DivaWaste' },
  // The bourse: lots offered to the eligible providers, and the negotiations on them.
  { path: 'marketplace', canActivate: [permissionGuard('marketplace.view')], loadComponent: () => import('./marketplace/marketplace').then((m) => m.MarketplaceListingsPage), title: 'Bourse — DivaWaste' },
  { path: 'marketplace/:ulid', canActivate: [permissionGuard('marketplace.view')], loadComponent: () => import('./marketplace/marketplace').then((m) => m.MarketplaceListingPage), title: 'Annonce — DivaWaste' },
  // Contracts with receivers and transporters: prices, fees, how many pickups and when.
  { path: 'contracts', canActivate: [permissionGuard('contracts.view')], loadComponent: () => import('./contracts/contracts').then((m) => m.ContractsPage), title: 'Contrats — DivaWaste' },
  { path: 'contracts/:ulid', canActivate: [permissionGuard('contracts.view')], loadComponent: () => import('./contracts/contracts').then((m) => m.ContractPage), title: 'Contrat — DivaWaste' },
  { path: 'providers', canActivate: [permissionGuard('providers.view')], loadComponent: () => import('./providers/providers').then((m) => m.ProvidersPage), title: 'Prestataires — DivaWaste' },
  // Compliance at a glance: what is missing to be in order; the lists below are the tabs of the section.
  { path: 'compliance', pathMatch: 'full', canActivate: [permissionGuard('documents.view')], loadComponent: () => import('./compliance/overview').then((m) => m.ComplianceOverviewPage), title: 'Conformité — DivaWaste' },
  { path: 'compliance/register', canActivate: [permissionGuard('documents.view')], loadComponent: () => import('./compliance/compliance').then((m) => m.RegisterPage), title: 'Registre — DivaWaste' },
  { path: 'compliance/manifests', canActivate: [permissionGuard('documents.view')], loadComponent: () => import('./compliance/compliance').then((m) => m.ManifestsPage), title: 'Bordereaux — DivaWaste' },
  { path: 'compliance/authorizations', canActivate: [permissionGuard('waste_types.view')], loadComponent: () => import('./compliance/authorizations').then((m) => m.AuthorizationsPage), title: 'Autorisations — DivaWaste' },
  { path: 'compliance/declarations', canActivate: [permissionGuard('declarations.prepare')], loadComponent: () => import('./compliance/compliance').then((m) => m.DeclarationsPage), title: 'Déclarations — DivaWaste' },
  { path: 'compliance/documents', canActivate: [permissionGuard('documents.view')], loadComponent: () => import('./compliance/compliance').then((m) => m.DocumentsPage), title: 'Documents — DivaWaste' },
  { path: 'production/partners', canActivate: [permissionGuard('production.view')], loadComponent: () => import('./production/partners').then((m) => m.PartnersPage), title: 'Clients et fournisseurs — DivaWaste' },
  { path: 'production/catalog', canActivate: [permissionGuard('production.view')], loadComponent: () => import('./production/materials').then((m) => m.MaterialsPage), title: 'Matières premières et articles — DivaWaste' },
  { path: 'production/catalog/:ulid', canActivate: [permissionGuard('production.view')], loadComponent: () => import('./production/material-detail').then((m) => m.MaterialDetailPage), title: 'Matière première — DivaWaste' },
  { path: 'production/lots', canActivate: [permissionGuard('production.view')], loadComponent: () => import('./production/lots').then((m) => m.ProductionLotsPage), title: 'Lots reçus et fabriqués — DivaWaste' },
  { path: 'production/finance', canActivate: [permissionGuard('production.finance.view')], loadComponent: () => import('./production/finance').then((m) => m.ProductionFinancePage), title: 'Coûts et gains — DivaWaste' },
  { path: 'production/orders', canActivate: [permissionGuard('production.view')], loadComponent: () => import('./production/orders').then((m) => m.OrdersPage), title: 'Commandes — DivaWaste' },
  { path: 'production/orders/:ulid', canActivate: [permissionGuard('production.view')], loadComponent: () => import('./production/order-detail').then((m) => m.OrderDetailPage), title: 'Commande — DivaWaste' },
  { path: 'production/manufacturing-orders', canActivate: [permissionGuard('production.view')], loadComponent: () => import('./production/orders').then((m) => m.ManufacturingOrdersPage), title: 'Ordres de fabrication — DivaWaste' },
  { path: 'production/manufacturing-orders/:ulid', canActivate: [permissionGuard('production.view')], loadComponent: () => import('./production/of-detail').then((m) => m.OfDetailPage), title: 'Ordre de fabrication — DivaWaste' },
  { path: 'production/stats', canActivate: [permissionGuard('production.stats.view')], loadComponent: () => import('./production/stats').then((m) => m.ProductionStatsPage), title: 'Statistiques de production — DivaWaste' },
  { path: 'production/import', canActivate: [permissionGuard('production.manage')], loadComponent: () => import('./production/import').then((m) => m.ImportPage), title: 'Import — DivaWaste' },
  { path: 'reports', canActivate: [permissionGuard('reports.view')], loadComponent: () => import('./reports/reports').then((m) => m.ReportsPage), title: 'Rapports — DivaWaste' },
  { path: 'sites', canActivate: [permissionGuard('sites.view')], loadComponent: () => import('./sites/sites').then((m) => m.SitesPage), title: 'Sites — DivaWaste' },
  { path: 'sites/:ulid', canActivate: [permissionGuard('sites.view')], loadComponent: () => import('./sites/sites').then((m) => m.SiteDetailPage), title: 'Site — DivaWaste' },
  { path: 'waste-types', canActivate: [permissionGuard('waste_types.view')], loadComponent: () => import('./catalog/waste-types').then((m) => m.WasteTypesPage), title: 'Types de déchets — DivaWaste' },
  { path: 'admin/subscription', canActivate: [permissionGuard('subscription.view')], loadComponent: () => import('./admin/admin').then((m) => m.SubscriptionPage), title: 'Abonnement — DivaWaste' },
  { path: 'admin/audit', canActivate: [permissionGuard('audit.view')], loadComponent: () => import('./admin/admin').then((m) => m.AuditPage), title: "Journal d'audit — DivaWaste" },
  // Settings moved out of the main navigation (G-05): one page each, reached from Paramètres.
  { path: 'settings/modules', canActivate: [permissionGuard('company.update')], loadComponent: () => import('./settings/settings').then((m) => m.ModulesPage), title: 'Modules — DivaWaste' },
  { path: 'settings/api-keys', canActivate: [permissionGuard('company.update')], loadComponent: () => import('./settings/api-keys').then((m) => m.ApiKeysPage), title: 'Clés d’accès API — DivaWaste' },
  { path: 'settings/reception', canActivate: [permissionGuard('waste_types.view')], loadComponent: () => import('./settings/settings').then((m) => m.ReceptionSettingsPage), title: 'Réception et écarts — DivaWaste' },
  { path: 'settings/colors', canActivate: [permissionGuard('waste_types.view')], loadComponent: () => import('./settings/settings').then((m) => m.ColorsSettingsPage), title: 'Couleurs — DivaWaste' },
  { path: 'settings/packaging', canActivate: [permissionGuard('sites.view')], loadComponent: () => import('./settings/settings').then((m) => m.PackagingSettingsPage), title: 'Conditionnements — DivaWaste' },
  // Notifications emitted by the API point here.
  { path: 'subscription', redirectTo: 'admin/subscription' },
  { path: 'compliance/manifests/:ulid', redirectTo: 'compliance/manifests' },
];

/** Workspace of a provider: the requests addressed to it, its documents, its public profile and accreditations. */
export const PROVIDER_ROUTES: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'pickups' },
  { path: 'pickups', canActivate: [permissionGuard('provider.pickups.view')], loadComponent: () => import('./pickups/pickups').then((m) => m.PickupsPage), title: 'Demandes — DivaWaste' },
  { path: 'pickups/:ulid', canActivate: [permissionGuard('provider.pickups.view')], loadComponent: () => import('./pickups/pickup-detail').then((m) => m.PickupDetailPage), title: 'Demande — DivaWaste' },
  { path: 'contracts', canActivate: [permissionGuard('provider.contracts.view')], loadComponent: () => import('./contracts/contracts').then((m) => m.ContractsPage), title: 'Contrats — DivaWaste' },
  { path: 'contracts/:ulid', canActivate: [permissionGuard('provider.contracts.view')], loadComponent: () => import('./contracts/contracts').then((m) => m.ContractPage), title: 'Contrat — DivaWaste' },
  { path: 'marketplace', canActivate: [permissionGuard('provider.marketplace.view')], loadComponent: () => import('./marketplace/marketplace').then((m) => m.MarketplaceCatalogPage), title: 'Bourse — DivaWaste' },
  { path: 'marketplace/:ulid', canActivate: [permissionGuard('provider.marketplace.view')], loadComponent: () => import('./marketplace/marketplace').then((m) => m.MarketplaceOfferPage), title: 'Annonce — DivaWaste' },
  { path: 'manifests', canActivate: [permissionGuard('provider.pickups.view')], loadComponent: () => import('./compliance/compliance').then((m) => m.ManifestsPage), title: 'Bordereaux — DivaWaste' },
  { path: 'manifests/:ulid', redirectTo: 'manifests' },
  { path: 'documents', canActivate: [permissionGuard('provider.pickups.view')], loadComponent: () => import('./compliance/compliance').then((m) => m.DocumentsPage), title: 'Documents — DivaWaste' },
  { path: 'profile', canActivate: [permissionGuard('provider.profile.manage')], loadComponent: () => import('./provider/provider').then((m) => m.ProviderProfilePage), title: 'Profil — DivaWaste' },
  { path: 'accreditations', canActivate: [permissionGuard('provider.accreditations.manage')], loadComponent: () => import('./provider/provider').then((m) => m.ProviderAccreditationsPage), title: 'Agréments — DivaWaste' },
];

/** Back-office of Diva Software staff. */
export const PLATFORM_ROUTES: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'overview' },
  { path: 'overview', canActivate: [permissionGuard('platform.companies.review')], loadComponent: () => import('./platform/back-office').then((m) => m.PlatformOverviewPage), title: 'Back-office — DivaWaste' },
  { path: 'companies', canActivate: [permissionGuard('platform.companies.review')], loadComponent: () => import('./platform/platform').then((m) => m.PlatformCompaniesPage), title: 'Sociétés — DivaWaste' },
  { path: 'companies/:ulid', canActivate: [permissionGuard('platform.companies.review')], loadComponent: () => import('./platform/platform').then((m) => m.PlatformCompanyPage), title: 'Société — DivaWaste' },
  { path: 'accreditations', canActivate: [permissionGuard('platform.providers.review')], loadComponent: () => import('./platform/platform').then((m) => m.PlatformAccreditationsPage), title: 'Agréments — DivaWaste' },
  { path: 'registry', canActivate: [permissionGuard('platform.providers.review', 'platform.support.view')], loadComponent: () => import('./providers/registry').then((m) => m.PlatformRegistryPage), title: 'Liste ANGed — DivaWaste' },
  { path: 'payments', canActivate: [permissionGuard('platform.payments.validate')], loadComponent: () => import('./platform/platform').then((m) => m.PlatformPaymentsPage), title: 'Virements — DivaWaste' },
  { path: 'plans', canActivate: [permissionGuard('platform.plans.manage')], loadComponent: () => import('./platform/back-office').then((m) => m.PlatformPlansPage), title: 'Formules — DivaWaste' },
  { path: 'invoices', canActivate: [permissionGuard('platform.invoices.manage')], loadComponent: () => import('./platform/back-office').then((m) => m.PlatformInvoicesPage), title: 'Factures — DivaWaste' },
  { path: 'activity', canActivate: [permissionGuard('platform.support.view')], loadComponent: () => import('./platform/back-office').then((m) => m.PlatformActivityPage), title: 'Activité — DivaWaste' },
  { path: 'catalog', canActivate: [permissionGuard('platform.catalog.manage')], loadComponent: () => import('./platform/platform').then((m) => m.PlatformCatalogPage), title: 'Catalogue — DivaWaste' },
  { path: 'roles', canActivate: [permissionGuard('platform.roles.manage')], loadComponent: () => import('./platform/platform').then((m) => m.PlatformRolesPage), title: 'Rôles — DivaWaste' },
];

/** Screens shared by industrial and provider accounts: the team and the company itself. */
export const TENANT_ROUTES: Routes = [
  { path: 'settings', pathMatch: 'full', loadComponent: () => import('./settings/settings').then((m) => m.SettingsPage), title: 'Paramètres — DivaWaste' },
  { path: 'admin/users', canActivate: [permissionGuard('users.view')], loadComponent: () => import('./admin/users').then((m) => m.UsersPage), title: 'Utilisateurs — DivaWaste' },
  { path: 'admin/company', canActivate: [permissionGuard('company.update')], loadComponent: () => import('./admin/admin').then((m) => m.CompanyPage), title: 'Société — DivaWaste' },
  { path: 'account/notifications', loadComponent: () => import('./account/account').then((m) => m.NotificationsPage), title: 'Notifications — DivaWaste' },
];

export const ACCOUNT_ROUTES: Routes = [
  { path: 'account/profile', loadComponent: () => import('./account/account').then((m) => m.ProfilePage), title: 'Mon profil — DivaWaste' },
  { path: 'account/security', loadComponent: () => import('./account/account').then((m) => m.SecurityPage), title: 'Sécurité — DivaWaste' },
];
