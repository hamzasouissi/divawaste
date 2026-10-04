import { Routes } from '@angular/router';
import { sessionGuard } from './app/guards/session.guard';

export const routes: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'sites' },
  { path: 'login', loadComponent: () => import('./pages/auth').then((m) => m.AuthPage) },
  { path: 'access-unavailable', loadComponent: () => import('./pages/auth').then((m) => m.AuthPage) },
  { path: 'sites/new', canActivate: [sessionGuard], loadComponent: () => import('./pages/sites/create-site.page').then((m) => m.CreateSitePage) },
  { path: 'sites', canActivate: [sessionGuard], loadComponent: () => import('./pages/sites').then((m) => m.SitesPage) },
  { path: 'stock-thresholds', canActivate: [sessionGuard], loadComponent: () => import('./pages/stock-thresholds').then((m) => m.StockThresholdsPage) },
  { path: 'catalog/waste-types', canActivate: [sessionGuard], loadComponent: () => import('./pages/waste-types').then((m) => m.WasteTypesPage) },
  { path: 'providers', canActivate: [sessionGuard], loadComponent: () => import('./pages/providers').then((m) => m.ProvidersPage) },
  { path: 'documents', canActivate: [sessionGuard], loadComponent: () => import('./pages/documents').then((m) => m.DocumentsPage) },
  { path: 'reports', canActivate: [sessionGuard], loadComponent: () => import('./pages/reports').then((m) => m.ReportsPage) },
  { path: 'notifications', canActivate: [sessionGuard], loadComponent: () => import('./pages/notifications').then((m) => m.NotificationsPage) },
  { path: 'lots/:ulid', canActivate: [sessionGuard], loadComponent: () => import('./pages/lot-detail').then((m) => m.LotDetailPage) },
  { path: 'pickups/:ulid', canActivate: [sessionGuard], loadComponent: () => import('./pages/pickup-detail').then((m) => m.PickupDetailPage) },
  { path: '**', redirectTo: 'sites' }
];
