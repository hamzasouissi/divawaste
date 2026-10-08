import { Audience, Session } from '../core/auth/session';

export interface NavItem {
  path: string;
  /** Translation key. */
  label: string;
  icon: string;
  /** Shown when the user holds at least one of these permissions (tenant or platform). */
  permissions?: string[];
}

export interface NavGroup {
  label: string;
  items: NavItem[];
}

/**
 * Information architecture, per kind of account. The seven roles of the cahier do not see the same product:
 * what appears is the intersection of the account's area and the user's permissions, so a director gets
 * dashboards and reports, an auditor a read-only trail, a provider its own workflow.
 */
const NAVIGATION: Record<Exclude<Audience, 'none'>, NavGroup[]> = {
  industrial: [
    { label: 'nav.groups.overview', items: [{ path: '/dashboard', label: 'nav.dashboard', icon: 'insights', permissions: ['dashboard.view'] }] },
    {
      label: 'nav.groups.operations',
      items: [
        { path: '/lots', label: 'nav.lots', icon: 'inventory_2', permissions: ['lots.view'] },
        { path: '/stock', label: 'nav.stock', icon: 'warehouse', permissions: ['stock.view'] },
        { path: '/pickups', label: 'nav.pickups', icon: 'local_shipping', permissions: ['pickups.view'] },
        { path: '/providers', label: 'nav.providers', icon: 'handshake', permissions: ['providers.view'] },
      ],
    },
    {
      label: 'nav.groups.production',
      items: [
        { path: '/production/manufacturing-orders', label: 'nav.manufacturingOrders', icon: 'precision_manufacturing', permissions: ['production.view'] },
        { path: '/production/orders', label: 'nav.orders', icon: 'receipt_long', permissions: ['production.view'] },
        { path: '/production/catalog', label: 'nav.productionCatalog', icon: 'checkroom', permissions: ['production.view'] },
        { path: '/production/partners', label: 'nav.productionPartners', icon: 'groups', permissions: ['production.view'] },
        { path: '/production/import', label: 'nav.productionImport', icon: 'upload_file', permissions: ['production.manage'] },
      ],
    },
    {
      label: 'nav.groups.compliance',
      items: [
        { path: '/compliance/register', label: 'nav.register', icon: 'menu_book', permissions: ['documents.view'] },
        { path: '/compliance/manifests', label: 'nav.manifests', icon: 'assignment_late', permissions: ['documents.view'] },
        { path: '/compliance/authorizations', label: 'nav.authorizations', icon: 'verified', permissions: ['waste_types.view'] },
        { path: '/compliance/declarations', label: 'nav.declarations', icon: 'fact_check', permissions: ['declarations.prepare'] },
        { path: '/compliance/documents', label: 'nav.documents', icon: 'folder_open', permissions: ['documents.view'] },
        { path: '/reports', label: 'nav.reports', icon: 'summarize', permissions: ['reports.view'] },
      ],
    },
    {
      label: 'nav.groups.reference',
      items: [
        { path: '/sites', label: 'nav.sites', icon: 'factory', permissions: ['sites.view'] },
        { path: '/waste-types', label: 'nav.wasteTypes', icon: 'category', permissions: ['waste_types.view'] },
      ],
    },
    {
      label: 'nav.groups.admin',
      items: [
        { path: '/admin/users', label: 'nav.users', icon: 'group', permissions: ['users.view'] },
        { path: '/admin/subscription', label: 'nav.subscription', icon: 'credit_card', permissions: ['subscription.view'] },
        { path: '/admin/audit', label: 'nav.audit', icon: 'history', permissions: ['audit.view'] },
        { path: '/admin/company', label: 'nav.company', icon: 'business', permissions: ['company.update'] },
      ],
    },
    { label: 'nav.groups.workshop', items: [{ path: '/m', label: 'nav.workshop', icon: 'qr_code_scanner', permissions: ['app.mobile.access'] }] },
  ],
  provider: [
    {
      label: 'nav.groups.provider',
      items: [
        { path: '/provider/pickups', label: 'nav.providerPickups', icon: 'local_shipping', permissions: ['provider.pickups.view'] },
        { path: '/provider/manifests', label: 'nav.manifests', icon: 'assignment_late', permissions: ['provider.manifests.sign', 'provider.pickups.view'] },
        { path: '/provider/documents', label: 'nav.documents', icon: 'folder_open', permissions: ['provider.pickups.view'] },
      ],
    },
    {
      label: 'nav.groups.providerAccount',
      items: [
        { path: '/provider/profile', label: 'nav.providerProfile', icon: 'storefront', permissions: ['provider.profile.manage'] },
        { path: '/provider/accreditations', label: 'nav.accreditations', icon: 'verified', permissions: ['provider.accreditations.manage'] },
        { path: '/admin/users', label: 'nav.users', icon: 'group', permissions: ['users.view'] },
      ],
    },
  ],
  // Diva Software operating the SaaS: its own vocabulary and order, nothing of a client workspace.
  platform: [
    { label: 'nav.groups.platform', items: [{ path: '/platform/overview', label: 'nav.platformOverview', icon: 'space_dashboard', permissions: ['platform.companies.review'] }] },
    {
      label: 'nav.groups.platformCustomers',
      items: [
        { path: '/platform/companies', label: 'nav.platformCompanies', icon: 'domain', permissions: ['platform.companies.review'] },
        { path: '/platform/accreditations', label: 'nav.platformAccreditations', icon: 'verified', permissions: ['platform.providers.review'] },
      ],
    },
    {
      label: 'nav.groups.platformBilling',
      items: [
        { path: '/platform/plans', label: 'nav.platformPlans', icon: 'sell', permissions: ['platform.plans.manage'] },
        { path: '/platform/invoices', label: 'nav.platformInvoices', icon: 'receipt_long', permissions: ['platform.invoices.manage'] },
        { path: '/platform/payments', label: 'nav.platformPayments', icon: 'account_balance', permissions: ['platform.payments.validate'] },
      ],
    },
    {
      label: 'nav.groups.platformReference',
      items: [
        { path: '/platform/catalog', label: 'nav.platformCatalog', icon: 'category', permissions: ['platform.catalog.manage'] },
        { path: '/platform/roles', label: 'nav.platformRoles', icon: 'admin_panel_settings', permissions: ['platform.roles.manage'] },
      ],
    },
    { label: 'nav.groups.platformMonitoring', items: [{ path: '/platform/activity', label: 'nav.platformActivity', icon: 'history', permissions: ['platform.support.view'] }] },
  ],
};

export function navigationFor(session: Session): NavGroup[] {
  const audience = session.audience();
  if (audience === 'none') {
    return [];
  }
  const allowed = (item: NavItem): boolean => !item.permissions || item.permissions.some((permission) => session.can(permission) || session.canPlatform(permission));

  return NAVIGATION[audience].map((group) => ({ ...group, items: group.items.filter(allowed) })).filter((group) => group.items.length > 0);
}
