import { Routes } from '@angular/router';

import { audienceGuard, authGuard, guestGuard } from './core/auth/guards';

/**
 * Three applications in one bundle, each loaded on demand: the public account flows, the desktop workspace
 * (industrial, provider and platform areas share the shell) and the workshop application under /m.
 */
export const routes: Routes = [
  {
    path: '',
    loadComponent: () => import('./features/auth/auth-layout').then((m) => m.AuthLayoutComponent),
    children: [
      { path: 'login', canActivate: [guestGuard], loadComponent: () => import('./features/auth/login').then((m) => m.LoginPage), title: 'DivaWaste — Connexion' },
      { path: 'register', canActivate: [guestGuard], loadComponent: () => import('./features/auth/register').then((m) => m.RegisterPage), title: 'DivaWaste — Inscription' },
      { path: 'forgot-password', loadComponent: () => import('./features/auth/account-flows').then((m) => m.ForgotPasswordPage) },
      { path: 'reset-password', loadComponent: () => import('./features/auth/account-flows').then((m) => m.ResetPasswordPage) },
      { path: 'verify-email', loadComponent: () => import('./features/auth/account-flows').then((m) => m.VerifyEmailPage) },
      { path: 'invitation', loadComponent: () => import('./features/auth/account-flows').then((m) => m.InvitationPage) },
    ],
  },
  { path: 'account-status', canActivate: [authGuard], loadComponent: () => import('./features/auth/account-flows').then((m) => m.AccountStatusPage) },
  {
    path: 'm',
    canActivate: [authGuard, audienceGuard('industrial')],
    loadComponent: () => import('./features/mobile/mobile').then((m) => m.MobileShellComponent),
    title: 'DivaWaste — Atelier',
    children: [
      { path: '', loadComponent: () => import('./features/mobile/mobile').then((m) => m.MobileHomePage) },
      { path: 'lots/new', loadComponent: () => import('./features/mobile/mobile').then((m) => m.MobileNewLotPage) },
      { path: 'lots/:tag', loadComponent: () => import('./features/mobile/mobile').then((m) => m.MobileLotPage) },
      { path: 'scan', loadComponent: () => import('./features/mobile/mobile').then((m) => m.MobileScanPage) },
      { path: 'loading', loadComponent: () => import('./features/mobile/mobile').then((m) => m.MobileLoadingPage) },
      { path: 'sync', loadComponent: () => import('./features/mobile/mobile').then((m) => m.MobileSyncPage) },
    ],
  },
  {
    path: '',
    canActivate: [authGuard],
    loadComponent: () => import('./layout/shell').then((m) => m.AppShellComponent),
    children: [
      { path: '', canActivate: [audienceGuard('industrial')], loadChildren: () => import('./features/industrial.routes').then((m) => m.INDUSTRIAL_ROUTES) },
    ],
  },
  { path: '**', redirectTo: 'login' },
];
