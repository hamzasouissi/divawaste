import { ChangeDetectionStrategy, Component, OnDestroy, computed, inject, signal } from '@angular/core';
import { MatBadgeModule } from '@angular/material/badge';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { MatTooltipModule } from '@angular/material/tooltip';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { filter } from 'rxjs';

import { Api } from '../core/api/api';
import { AppNotification } from '../core/api/models';
import { Session } from '../core/auth/session';
import { DatePipe } from '../core/format/pipes';
import { navigationFor } from './navigation';

/**
 * Frame of the desktop application: navigation rail by role, top bar with the company, the notifications and
 * the account menu. On narrow screens the navigation becomes a drawer.
 */
@Component({
  selector: 'dw-app-shell',
  imports: [RouterOutlet, RouterLink, RouterLinkActive, MatBadgeModule, MatButtonModule, MatIconModule, MatMenuModule, MatTooltipModule, TranslocoDirective, DatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <a class="skip" href="#main">{{ t('shell.skip') }}</a>

      <nav class="nav" [class.open]="navOpen()" [attr.aria-label]="t('shell.navigation')">
        <a class="brand" [routerLink]="session.home()">
          <img src="icons/icon.svg" alt="" width="28" height="28" />
          <span>DivaWaste</span>
        </a>
        <div class="groups">
          @for (group of groups(); track group.label) {
            <div class="group">
              <div class="group-label">{{ t(group.label) }}</div>
              @for (item of group.items; track item.path) {
                <a class="item" [routerLink]="item.path" routerLinkActive="active" (click)="navOpen.set(false)">
                  <mat-icon aria-hidden="true">{{ item.icon }}</mat-icon>
                  <span>{{ t(item.label) }}</span>
                </a>
              }
            </div>
          }
        </div>
        @if (session.company(); as company) {
          <div class="company">
            <div class="dw-label">{{ t('shell.company') }}</div>
            <div class="company-name" [matTooltip]="company.legal_name">{{ company.legal_name }}</div>
          </div>
        }
      </nav>
      @if (navOpen()) {
        <button type="button" class="scrim" (click)="navOpen.set(false)" [attr.aria-label]="t('common.close')"></button>
      }

      <div class="body">
        <header class="bar">
          <button mat-icon-button type="button" class="menu" (click)="navOpen.set(true)" [attr.aria-label]="t('shell.openNavigation')">
            <mat-icon>menu</mat-icon>
          </button>
          <span class="dw-spacer"></span>

          @if (session.audience() !== 'platform') {
            <button mat-icon-button type="button" [matMenuTriggerFor]="notifMenu" (menuOpened)="loadNotifications()"
              [attr.aria-label]="t('shell.notifications', { count: unread() })">
              <mat-icon [matBadge]="unread()" [matBadgeHidden]="unread() === 0" matBadgeSize="small" aria-hidden="false">notifications</mat-icon>
            </button>
            <mat-menu #notifMenu="matMenu" class="dw-notif-menu" xPosition="before">
              <div class="notif-head" (click)="$event.stopPropagation()" (keydown)="$event.stopPropagation()" tabindex="-1">
                <span class="dw-h3">{{ t('notifications.title') }}</span>
                <span class="dw-spacer"></span>
                @if (unread() > 0) {
                  <button mat-button type="button" (click)="readAll()">{{ t('notifications.readAll') }}</button>
                }
              </div>
              @for (item of notifications(); track item.id) {
                <button mat-menu-item type="button" class="notif" [class.unread]="!item.read_at" (click)="open(item)">
                  <span class="notif-title">{{ t('notifications.types.' + item.type + '.title', item.params) }}</span>
                  <span class="notif-body">{{ t('notifications.types.' + item.type + '.body', item.params) }}</span>
                  <span class="notif-date">{{ item.created_at | dwDate: 'datetime' }}</span>
                </button>
              } @empty {
                <div class="notif-empty dw-muted">{{ t('notifications.empty') }}</div>
              }
              <a mat-menu-item routerLink="/account/notifications" class="notif-all">{{ t('notifications.all') }}</a>
            </mat-menu>
          }

          <button mat-button type="button" class="user" [matMenuTriggerFor]="userMenu">
            <span class="avatar" aria-hidden="true">{{ initials() }}</span>
            <span class="user-name">{{ session.me()?.first_name }} {{ session.me()?.last_name }}</span>
            <mat-icon aria-hidden="true">expand_more</mat-icon>
          </button>
          <mat-menu #userMenu="matMenu" xPosition="before">
            <a mat-menu-item routerLink="/account/profile"><mat-icon>person</mat-icon>{{ t('shell.profile') }}</a>
            <a mat-menu-item routerLink="/account/security"><mat-icon>lock</mat-icon>{{ t('shell.security') }}</a>
            @if (otherCompanies().length > 0) {
              <button mat-menu-item type="button" [matMenuTriggerFor]="companyMenu"><mat-icon>swap_horiz</mat-icon>{{ t('shell.switchCompany') }}</button>
            }
            <button mat-menu-item type="button" (click)="logout()"><mat-icon>logout</mat-icon>{{ t('shell.logout') }}</button>
          </mat-menu>
          <mat-menu #companyMenu="matMenu">
            @for (membership of otherCompanies(); track membership.company) {
              <button mat-menu-item type="button" (click)="switchCompany(membership.company)">{{ membership.legal_name }}</button>
            }
          </mat-menu>
        </header>

        <main id="main" class="content" tabindex="-1">
          <router-outlet />
        </main>
      </div>
    </ng-container>
  `,
  styles: `
    :host { display: grid; grid-template-columns: var(--dw-shell-nav) minmax(0, 1fr); min-height: 100dvh; }
    .skip { position: absolute; inset-inline-start: -999px; }
    .skip:focus { inset-inline-start: 8px; inset-block-start: 8px; z-index: 30; background: var(--dw-surface); padding: 8px 12px; border-radius: var(--dw-radius); }

    .nav { position: sticky; inset-block-start: 0; height: 100dvh; display: flex; flex-direction: column; background: var(--dw-nav); color: var(--dw-nav-ink); }
    .brand { display: flex; align-items: center; gap: 10px; height: var(--dw-shell-bar); padding-inline: 16px; color: #fff; font-weight: 600; font-size: 15px; letter-spacing: -0.01em; }
    .brand:hover { text-decoration: none; }
    .groups { flex: 1; overflow-y: auto; padding: 8px 8px 16px; }
    .group { margin-block-end: 14px; }
    .group-label { padding: 6px 10px 4px; font-size: 10.5px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: #7f9390; }
    .item { display: flex; align-items: center; gap: 10px; height: 34px; padding-inline: 10px; border-radius: 6px; color: var(--dw-nav-ink); font-size: 13.5px; }
    .item mat-icon { font-size: 18px; width: 18px; height: 18px; }
    .item:hover { background: rgb(255 255 255 / 7%); text-decoration: none; color: #fff; }
    .item.active { background: rgb(127 214 194 / 16%); color: #fff; font-weight: 500; }
    .item:focus-visible { outline: 2px solid #7fd6c2; outline-offset: -2px; }
    .company { padding: 12px 18px 16px; border-block-start: 1px solid rgb(255 255 255 / 8%); }
    .company .dw-label { color: #7f9390; }
    .company-name { color: #fff; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    .body { display: flex; flex-direction: column; min-width: 0; }
    .bar { position: sticky; inset-block-start: 0; z-index: 5; display: flex; align-items: center; gap: 4px; height: var(--dw-shell-bar); padding-inline: 12px 16px;
      background: var(--dw-surface); border-block-end: 1px solid var(--dw-line); }
    .menu { display: none; }
    .user { padding-inline: 6px 2px; }
    .avatar { display: inline-grid; place-items: center; width: 28px; height: 28px; border-radius: 50%; background: var(--dw-primary-soft); color: var(--dw-primary-strong); font-size: 12px; font-weight: 600; margin-inline-end: 8px; }
    .content { flex: 1; padding: var(--dw-5); max-width: 1480px; width: 100%; margin-inline: auto; outline: none; }
    .scrim { display: none; }

    .notif-head { display: flex; align-items: center; padding: 6px 8px 6px 16px; min-width: 340px; border-block-end: 1px solid var(--dw-line-soft); }
    .notif { height: auto; padding-block: 8px; white-space: normal; border-inline-start: 3px solid transparent; }
    .notif.unread { border-inline-start-color: var(--dw-primary); background: var(--dw-primary-soft); }
    .notif-title { display: block; font-weight: 600; font-size: 13px; }
    .notif-body { display: block; font-size: 12.5px; color: var(--dw-ink-2); max-width: 340px; }
    .notif-date { display: block; font-size: 11px; color: var(--dw-ink-3); }
    .notif-empty { padding: 20px 16px; font-size: 13px; }
    .notif-all { font-size: 13px; color: var(--dw-primary); border-block-start: 1px solid var(--dw-line-soft); }

    @media (max-width: 960px) {
      :host { grid-template-columns: minmax(0, 1fr); }
      .nav { position: fixed; inset-block: 0; inset-inline-start: 0; width: 264px; z-index: 20; transform: translateX(-100%); transition: transform 160ms; }
      :host-context([dir='rtl']) .nav { transform: translateX(100%); }
      .nav.open { transform: none; box-shadow: var(--dw-shadow-pop); }
      .scrim { display: block; position: fixed; inset: 0; z-index: 15; border: 0; background: rgb(16 32 31 / 45%); }
      .menu { display: inline-flex; }
      .user-name { display: none; }
      .content { padding: var(--dw-4); }
    }
  `,
})
export class AppShellComponent implements OnDestroy {
  protected readonly session = inject(Session);
  private readonly api = inject(Api);
  private readonly router = inject(Router);

  protected readonly navOpen = signal(false);
  protected readonly notifications = signal<AppNotification[]>([]);
  protected readonly unread = signal(0);

  protected readonly groups = computed(() => navigationFor(this.session));
  protected readonly initials = computed(() => `${this.session.me()?.first_name?.[0] ?? ''}${this.session.me()?.last_name?.[0] ?? ''}`.toUpperCase());
  protected readonly otherCompanies = computed(() =>
    (this.session.me()?.memberships ?? []).filter((membership) => membership.status === 'active' && membership.company !== this.session.company()?.ulid),
  );

  private readonly poll = setInterval(() => this.loadNotifications(), 60_000);

  constructor() {
    this.loadNotifications();
    // After each navigation, focus moves to the content so keyboard and screen-reader users land on the new page.
    this.router.events.pipe(filter((event) => event instanceof NavigationEnd)).subscribe(() => document.getElementById('main')?.focus({ preventScroll: true }));
  }

  ngOnDestroy(): void {
    clearInterval(this.poll);
  }

  protected loadNotifications(): void {
    if (this.session.audience() === 'platform' || this.session.audience() === 'none') {
      return;
    }
    this.api.raw<{ data: AppNotification[]; meta: { unread_count: number } }>('me/notifications').subscribe({
      next: (page) => {
        this.notifications.set(page.data.slice(0, 8));
        this.unread.set(page.meta.unread_count);
      },
      // A failed poll is silent: the bell is secondary and the next poll will retry.
      error: () => undefined,
    });
  }

  protected open(item: AppNotification): void {
    if (!item.read_at) {
      this.api.post(`me/notifications/${item.id}/read`).subscribe({ next: () => this.loadNotifications(), error: () => undefined });
    }
    if (item.link) {
      void this.router.navigateByUrl(item.link);
    }
  }

  protected readAll(): void {
    this.api.post('me/notifications/read-all').subscribe({ next: () => this.loadNotifications(), error: () => undefined });
  }

  protected async switchCompany(company: string): Promise<void> {
    await this.session.switchCompany(company);
    await this.router.navigateByUrl(this.session.home());
  }

  protected async logout(): Promise<void> {
    await this.session.logout();
    await this.router.navigate(['/login']);
  }
}
