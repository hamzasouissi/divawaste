import { Routes } from '@angular/router';

import { permissionGuard } from '../core/auth/guards';

/** Workspace of an industrial company. Each screen is guarded by the permission the API will also require. */
export const INDUSTRIAL_ROUTES: Routes = [
  { path: 'dashboard', canActivate: [permissionGuard('dashboard.view')], loadComponent: () => import('./dashboard/dashboard').then((m) => m.DashboardPage), title: 'Tableau de bord — DivaWaste' },
  { path: 'lots', canActivate: [permissionGuard('lots.view')], loadComponent: () => import('./lots/lots').then((m) => m.LotsPage), title: 'Lots — DivaWaste' },
  { path: 'lots/:ulid', canActivate: [permissionGuard('lots.view')], loadComponent: () => import('./lots/lot-detail').then((m) => m.LotDetailPage), title: 'Lot — DivaWaste' },
  { path: 'stock', canActivate: [permissionGuard('stock.view')], loadComponent: () => import('./stock/stock').then((m) => m.StockPage), title: 'Stock — DivaWaste' },
  { path: 'pickups', canActivate: [permissionGuard('pickups.view')], loadComponent: () => import('./pickups/pickups').then((m) => m.PickupsPage), title: 'Enlèvements — DivaWaste' },
  { path: 'pickups/new', canActivate: [permissionGuard('pickups.request')], loadComponent: () => import('./pickups/pickup-create').then((m) => m.PickupCreatePage), title: 'Nouvelle demande — DivaWaste' },
  { path: 'pickups/:ulid', canActivate: [permissionGuard('pickups.view')], loadComponent: () => import('./pickups/pickup-detail').then((m) => m.PickupDetailPage), title: 'Enlèvement — DivaWaste' },
  { path: 'sites', canActivate: [permissionGuard('sites.view')], loadComponent: () => import('./sites/sites').then((m) => m.SitesPage), title: 'Sites — DivaWaste' },
  { path: 'sites/:ulid', canActivate: [permissionGuard('sites.view')], loadComponent: () => import('./sites/sites').then((m) => m.SiteDetailPage), title: 'Site — DivaWaste' },
  { path: 'waste-types', canActivate: [permissionGuard('waste_types.view')], loadComponent: () => import('./catalog/waste-types').then((m) => m.WasteTypesPage), title: 'Types de déchets — DivaWaste' },
];
