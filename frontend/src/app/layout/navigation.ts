import { Audience, Session } from '../core/auth/session';
import { CompanyModule } from '../core/api/models';

/** A page of a section, shown as a tab above the content when the section has more than one. */
export interface NavTab {
  path: string;
  /** Translation key. */
  label: string;
  /** Shown when the user holds at least one of these permissions (tenant or platform). */
  permissions?: string[];
  /** Shown only when the company uses this part of the product. */
  module?: CompanyModule;
  /** Other addresses that belong to this tab (detail pages). */
  match?: string[];
}

export interface NavItem {
  /** Where the entry leads: its first tab the user may open. */
  path: string;
  label: string;
  icon: string;
  tabs: NavTab[];
  /** Addresses of the section that are not tabs (settings pages, detail pages). */
  match?: string[];
}

export interface NavGroup {
  /** Translation key; empty for the main group, which has no heading. */
  label: string;
  items: NavItem[];
}

interface SectionDef {
  label: string;
  icon: string;
  tabs: NavTab[];
  match?: string[];
}

interface GroupDef {
  label: string;
  sections: SectionDef[];
}

const tab = (path: string, label: string, permissions?: string[], module?: CompanyModule, match?: string[]): NavTab => ({ path, label, permissions, module, match });

/**
 * Information architecture, per kind of account: a few sections named after the work (G-01), each with its pages as
 * tabs. What appears is the intersection of the account's area, the modules the company uses (G-02) and the user's
 * permissions. Settings live under the gear at the foot of the navigation.
 */
const NAVIGATION: Record<Exclude<Audience, 'none'>, GroupDef[]> = {
  industrial: [
    {
      label: '',
      sections: [
        { label: 'nav.home', icon: 'space_dashboard', tabs: [tab('/dashboard', 'nav.dashboard', ['dashboard.view']), tab('/onboarding', 'onboarding.title')] },
        { label: 'nav.waste', icon: 'inventory_2', tabs: [tab('/lots', 'nav.tabs.lots', ['lots.view']), tab('/stock', 'nav.stock', ['stock.view']), tab('/sites', 'nav.tabs.sitesZones', ['sites.view'])] },
        {
          label: 'nav.pickups',
          icon: 'local_shipping',
          tabs: [
            tab('/pickups', 'nav.tabs.requests', ['pickups.view']),
            tab('/providers', 'nav.providers', ['providers.view']),
            tab('/contracts', 'nav.contracts', ['contracts.view'], 'contracts'),
            tab('/marketplace', 'nav.marketplace', ['marketplace.view'], 'marketplace'),
          ],
        },
        {
          label: 'nav.compliance',
          icon: 'verified_user',
          tabs: [
            tab('/compliance', 'nav.tabs.complianceOverview', ['documents.view']),
            tab('/compliance/register', 'nav.tabs.register', ['documents.view']),
            tab('/compliance/manifests', 'nav.tabs.manifests', ['documents.view']),
            tab('/compliance/documents', 'nav.documents', ['documents.view']),
            tab('/compliance/declarations', 'nav.tabs.declarations', ['declarations.prepare']),
            tab('/compliance/authorizations', 'nav.authorizations', ['waste_types.view'], 'authorizations'),
          ],
        },
        { label: 'nav.reports', icon: 'summarize', tabs: [tab('/reports', 'nav.reports', ['reports.view'])] },
      ],
    },
    // Production comes after the waste it explains: where a waste comes from, what it was made of, for whom. The
    // statistics that speak about waste lead; reference data follow, money comes last.
    {
      label: 'nav.groups.production',
      sections: [
        {
          label: 'nav.manufacturing',
          icon: 'precision_manufacturing',
          tabs: [
            tab('/production/manufacturing-orders', 'nav.tabs.manufacturingOrders', ['production.view'], 'production'),
            tab('/production/orders', 'nav.orders', ['production.view'], 'production'),
            tab('/production/lots', 'nav.tabs.productionLots', ['production.view'], 'production'),
          ],
        },
        {
          label: 'nav.productionReference',
          icon: 'checkroom',
          tabs: [
            tab('/production/catalog', 'nav.productionCatalog', ['production.view'], 'production'),
            tab('/production/partners', 'nav.productionPartners', ['production.view'], 'production'),
          ],
        },
        {
          label: 'nav.productionAnalysis',
          icon: 'query_stats',
          tabs: [
            tab('/production/stats', 'nav.productionStats', ['production.stats.view'], 'production'),
            tab('/production/finance', 'nav.productionFinance', ['production.finance.view'], 'production_finance'),
          ],
        },
      ],
    },
  ],
  provider: [
    {
      label: '',
      sections: [
        { label: 'nav.providerPickups', icon: 'local_shipping', tabs: [tab('/provider/pickups', 'nav.providerPickups', ['provider.pickups.view'])] },
        { label: 'nav.marketplace', icon: 'storefront', tabs: [tab('/provider/marketplace', 'nav.marketplace', ['provider.marketplace.view'])] },
        { label: 'nav.contracts', icon: 'handshake', tabs: [tab('/provider/contracts', 'nav.contracts', ['provider.contracts.view'])] },
        {
          label: 'nav.documents',
          icon: 'folder_open',
          tabs: [tab('/provider/manifests', 'nav.tabs.manifests', ['provider.manifests.sign', 'provider.pickups.view']), tab('/provider/documents', 'nav.tabs.allDocuments', ['provider.pickups.view'])],
        },
        {
          label: 'nav.providerSpace',
          icon: 'domain',
          tabs: [tab('/provider/profile', 'nav.tabs.providerProfile', ['provider.profile.manage']), tab('/provider/accreditations', 'nav.accreditations', ['provider.accreditations.manage'])],
        },
      ],
    },
  ],
  // Diva Software operating the SaaS: its own vocabulary and order, nothing of a client workspace.
  platform: [
    { label: 'nav.groups.platform', sections: [{ label: 'nav.platformOverview', icon: 'space_dashboard', tabs: [tab('/platform/overview', 'nav.platformOverview', ['platform.companies.review'])] }] },
    {
      label: 'nav.groups.platformCustomers',
      sections: [
        { label: 'nav.platformCompanies', icon: 'domain', tabs: [tab('/platform/companies', 'nav.platformCompanies', ['platform.companies.review'])] },
        { label: 'nav.platformAccreditations', icon: 'verified', tabs: [tab('/platform/accreditations', 'nav.platformAccreditations', ['platform.providers.review'])] },
        { label: 'nav.platformRegistry', icon: 'travel_explore', tabs: [tab('/platform/registry', 'nav.platformRegistry', ['platform.providers.review', 'platform.support.view'])] },
      ],
    },
    {
      label: 'nav.groups.platformBilling',
      sections: [
        { label: 'nav.platformPlans', icon: 'sell', tabs: [tab('/platform/plans', 'nav.platformPlans', ['platform.plans.manage'])] },
        { label: 'nav.platformInvoices', icon: 'receipt_long', tabs: [tab('/platform/invoices', 'nav.platformInvoices', ['platform.invoices.manage'])] },
        { label: 'nav.platformPayments', icon: 'account_balance', tabs: [tab('/platform/payments', 'nav.platformPayments', ['platform.payments.validate'])] },
      ],
    },
    {
      label: 'nav.groups.platformReference',
      sections: [
        { label: 'nav.platformCatalog', icon: 'category', tabs: [tab('/platform/catalog', 'nav.platformCatalog', ['platform.catalog.manage'])] },
        { label: 'nav.platformRoles', icon: 'admin_panel_settings', tabs: [tab('/platform/roles', 'nav.platformRoles', ['platform.roles.manage'])] },
      ],
    },
    { label: 'nav.groups.platformMonitoring', sections: [{ label: 'nav.platformActivity', icon: 'history', tabs: [tab('/platform/activity', 'nav.platformActivity', ['platform.support.view'])] }] },
  ],
};

/** The settings of a company (CO-02, G-05): one page under the gear, each card leading to its screen. */
export const SETTINGS_PATHS = ['/settings', '/sites', '/waste-types', '/admin', '/production/import'];

function allowedTab(session: Session, item: NavTab): boolean {
  if (item.module && !session.uses(item.module)) {
    return false;
  }

  return !item.permissions || item.permissions.some((permission) => session.can(permission) || session.canPlatform(permission));
}

export function navigationFor(session: Session): NavGroup[] {
  const audience = session.audience();
  if (audience === 'none') {
    return [];
  }

  return NAVIGATION[audience]
    .map((group) => ({
      label: group.label,
      items: group.sections
        .map((section) => {
          // The onboarding tab only exists while the start-up guide is open; it never leads the section.
          const tabs = section.tabs.filter((candidate) => allowedTab(session, candidate) && candidate.path !== '/onboarding');
          return { label: section.label, icon: section.icon, tabs, match: section.match, path: tabs[0]?.path ?? '' };
        })
        .filter((item) => item.tabs.length > 0),
    }))
    .filter((group) => group.items.length > 0);
}

function within(url: string, path: string): boolean {
  return url === path || url.startsWith(`${path}/`);
}

/** The section and tab of an address, in the words of the navigation. The most specific tab wins. */
export function placeOf(groups: NavGroup[], url: string): { item: NavItem; tab: NavTab | null } | null {
  let best: { item: NavItem; tab: NavTab | null; length: number } | null = null;
  for (const group of groups) {
    for (const item of group.items) {
      for (const candidate of item.tabs) {
        for (const path of [candidate.path, ...(candidate.match ?? [])]) {
          if (within(url, path) && (!best || path.length > best.length)) {
            best = { item, tab: candidate, length: path.length };
          }
        }
      }
    }
  }

  return best ? { item: best.item, tab: best.tab } : null;
}
