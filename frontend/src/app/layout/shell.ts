import { ChangeDetectionStrategy, Component, OnDestroy, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { Badge } from 'primeng/badge';
import { ButtonDirective } from 'primeng/button';
import { Popover } from 'primeng/popover';
import { Tooltip } from 'primeng/tooltip';
import { filter, map, startWith } from 'rxjs';

import { Api } from '../core/api/api';
import { AppNotification } from '../core/api/models';
import { Session } from '../core/auth/session';
import { Toast } from '../core/feedback/toast';
import { DatePipe } from '../core/format/pipes';
import { IconComponent } from '../ui/icon';
import { MenuAction, MenuComponent } from '../ui/menu';
import { navigationFor } from './navigation';

const RAIL_KEY = 'dw.nav.collapsed';

/**
 * Frame of the desktop application: navigation by role (collapsible to an icon rail), top bar with the
 * position in the product, the notifications and the account menu. On narrow screens the navigation becomes
 * a drawer.
 */
@Component({
  selector: 'dw-app-shell',
  imports: [RouterOutlet, RouterLink, RouterLinkActive, Badge, TranslocoDirective, DatePipe, IconComponent, ButtonDirective, MenuComponent, Tooltip, Popover],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '[class.rail]': 'collapsed()' },
  template: `
    <ng-container *transloco="let t">
      <a class="skip" href="#main">{{ t('shell.skip') }}</a>

      <nav class="nav" [class.open]="navOpen()" [attr.aria-label]="t('shell.navigation')">
        <a class="brand" [routerLink]="session.home()">
          <img src="icons/icon.svg" alt="" width="26" height="26" />
          <span class="brand-name">DivaWaste<small>Textile</small></span>
        </a>
        <div class="groups dw-scroll-dark">
          @for (group of groups(); track group.label) {
            <div class="group">
              <div class="group-label"><span>{{ t(group.label) }}</span></div>
              @for (item of group.items; track item.path) {
                <a class="item" [routerLink]="item.path" routerLinkActive="active" (click)="navOpen.set(false)"
                  [pTooltip]="t(item.label)" tooltipPosition="right" [tooltipDisabled]="!collapsed()" [showDelay]="150">
                  <dw-icon>{{ item.icon }}</dw-icon>
                  <span class="item-label">{{ t(item.label) }}</span>
                </a>
              }
            </div>
          }
        </div>
        <div class="nav-foot">
          @if (session.company(); as company) {
            <div class="company" [pTooltip]="company.legal_name" tooltipPosition="right" [showDelay]="400">
              <span class="company-mark" aria-hidden="true">{{ company.legal_name.slice(0, 1) }}</span>
              <span class="company-text">
                <span class="company-label">{{ t('shell.company') }}</span>
                <span class="company-name">{{ company.legal_name }}</span>
              </span>
            </div>
          }
          <button type="button" class="collapse" (click)="toggleRail()" [attr.aria-label]="t(collapsed() ? 'common.expandNavigation' : 'common.collapseNavigation')"
            [attr.aria-pressed]="collapsed()" [pTooltip]="t(collapsed() ? 'common.expandNavigation' : 'common.collapseNavigation')" tooltipPosition="right" [showDelay]="400">
            <dw-icon>{{ collapsed() ? 'keyboard_double_arrow_right' : 'keyboard_double_arrow_left' }}</dw-icon>
          </button>
        </div>
      </nav>
      @if (navOpen()) {
        <button type="button" class="scrim" (click)="navOpen.set(false)" [attr.aria-label]="t('common.close')"></button>
      }

      <div class="body">
        <header class="bar">
          <button pButton text severity="secondary" type="button" class="dw-icon-btn menu" (click)="navOpen.set(true)" [attr.aria-label]="t('shell.openNavigation')">
            <dw-icon>menu</dw-icon>
          </button>
          @if (place(); as here) {
            <div class="crumbs" aria-hidden="true">
              <span>{{ t(here.group) }}</span>
              <dw-icon>chevron_right</dw-icon>
              <strong>{{ t(here.item) }}</strong>
            </div>
          }
          <span class="dw-spacer"></span>

          @if (session.audience() !== 'platform') {
            <button pButton text severity="secondary" type="button" class="dw-icon-btn bell" (click)="loadNotifications(); notif.toggle($event)"
              [attr.aria-label]="t('shell.notifications', { count: unread() })" aria-haspopup="dialog">
              <dw-icon>notifications</dw-icon>
              @if (unread() > 0) { <p-badge [value]="unread() > 9 ? '9+' : unread()" severity="danger" badgeSize="small" class="bell-count" /> }
            </button>
            <p-popover #notif styleClass="dw-notif">
              <div class="notif-head">
                <span class="dw-h3">{{ t('notifications.title') }}</span>
                <span class="dw-spacer"></span>
                @if (unread() > 0) {
                  <button pButton text size="small" type="button" (click)="readAll()">{{ t('notifications.readAll') }}</button>
                }
              </div>
              <div class="notif-list">
                @for (item of notifications(); track item.id) {
                  <button type="button" class="notif" [class.unread]="!item.read_at" (click)="open(item); notif.hide()">
                    <span class="notif-title">{{ t('notifications.types.' + item.type + '.title', item.params) }}</span>
                    <span class="notif-body">{{ t('notifications.types.' + item.type + '.body', item.params) }}</span>
                    <span class="notif-date">{{ item.created_at | dwDate: 'datetime' }}</span>
                  </button>
                } @empty {
                  <div class="notif-empty"><dw-icon>notifications_none</dw-icon>{{ t('notifications.empty') }}</div>
                }
              </div>
              <a routerLink="/account/notifications" class="notif-all" (click)="notif.hide()">{{ t('notifications.all') }}</a>
            </p-popover>
          }

          <button type="button" class="user" (click)="userMenu.open($event, accountActions())" aria-haspopup="menu">
            <span class="avatar" aria-hidden="true">{{ initials() }}</span>
            <span class="user-name">{{ session.me()?.first_name }} {{ session.me()?.last_name }}</span>
            <dw-icon>expand_more</dw-icon>
          </button>
          <dw-menu #userMenu />
        </header>

        <main id="main" class="content" tabindex="-1">
          <router-outlet />
        </main>
      </div>
    </ng-container>
  `,
  styles: `
    :host { display: grid; grid-template-columns: var(--dw-shell-nav) minmax(0, 1fr); min-height: 100dvh; transition: grid-template-columns 140ms ease; }
    :host(.rail) { grid-template-columns: var(--dw-shell-rail) minmax(0, 1fr); }
    .skip { position: absolute; inset-inline-start: -999px; }
    .skip:focus { inset-inline-start: 8px; inset-block-start: 8px; z-index: 30; background: var(--dw-surface); padding: 8px 12px; border-radius: var(--dw-radius); }

    /* Navigation */
    .nav { position: sticky; inset-block-start: 0; height: 100dvh; display: flex; flex-direction: column; min-width: 0; overflow: hidden; background: var(--dw-nav); color: var(--dw-nav-ink);
      border-inline-end: 1px solid rgb(255 255 255 / 4%); }
    .brand { display: flex; align-items: center; gap: 10px; flex: none; height: var(--dw-shell-bar); padding-inline: 15px; color: #fff; }
    .brand:hover { text-decoration: none; }
    .brand img { flex: none; }
    .brand-name { display: flex; align-items: baseline; gap: 6px; font-size: 14.5px; font-weight: 650; letter-spacing: -0.015em; white-space: nowrap; }
    .brand-name small { font-size: 11px; font-weight: 500; letter-spacing: 0.02em; color: var(--dw-nav-faint); }
    .groups { flex: 1; overflow-x: hidden; overflow-y: auto; padding: 6px 8px 12px; }
    .group + .group { margin-block-start: 10px; }
    .group-label { display: flex; align-items: center; height: 24px; padding-inline: 8px; font-size: 10.5px; font-weight: 600; letter-spacing: 0.07em; text-transform: uppercase; color: var(--dw-nav-faint); white-space: nowrap; }
    .item { position: relative; display: flex; align-items: center; gap: 10px; height: 32px; padding-inline: 9px; border-radius: var(--dw-radius); color: var(--dw-nav-ink); font-size: 13px;
      font-weight: 500; white-space: nowrap; transition: background-color 100ms, color 100ms; }
    .item dw-icon { font-size: 18px; color: #8ea3a1; transition: color 100ms; }
    .item:hover { background: rgb(255 255 255 / 6%); text-decoration: none; color: #fff; }
    .item:hover dw-icon { color: #cfe0de; }
    .item.active { background: rgb(255 255 255 / 9%); color: #fff; font-weight: 600; }
    .item.active dw-icon { color: #7fd6c2; }
    .item.active::before { content: ''; position: absolute; inset-inline-start: -8px; inset-block: 7px; width: 3px; border-radius: 0 3px 3px 0; background: #7fd6c2; }
    .item:focus-visible, .collapse:focus-visible { outline: 2px solid #7fd6c2; outline-offset: -2px; }
    .item-label { overflow: hidden; text-overflow: ellipsis; }

    .nav-foot { flex: none; display: flex; align-items: center; gap: 6px; padding: 8px; border-block-start: 1px solid rgb(255 255 255 / 7%); }
    .company { display: flex; align-items: center; gap: 9px; flex: 1 1 auto; min-width: 0; padding: 4px; }
    .company-mark { flex: none; display: grid; place-items: center; width: 26px; height: 26px; border-radius: 6px; background: rgb(127 214 194 / 16%); color: #a8e6d8; font-size: 12px; font-weight: 700; text-transform: uppercase; }
    .company-text { display: flex; flex-direction: column; min-width: 0; }
    .company-label { font-size: 10.5px; line-height: 13px; color: var(--dw-nav-faint); }
    .company-name { color: #fff; font-size: 12.5px; line-height: 17px; font-weight: 550; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .collapse { all: unset; flex: none; display: grid; place-items: center; width: 28px; height: 28px; border-radius: var(--dw-radius); cursor: pointer; color: var(--dw-nav-faint); }
    .collapse:hover { background: rgb(255 255 255 / 7%); color: #fff; }
    .collapse dw-icon { font-size: 18px; }

    /* Icon rail */
    :host(.rail) .brand { padding-inline: 15px; }
    :host(.rail) .brand-name, :host(.rail) .item-label, :host(.rail) .company-text { display: none; }
    :host(.rail) .group-label { padding-inline: 10px; }
    :host(.rail) .group-label span { display: none; }
    :host(.rail) .group-label::before { content: ''; flex: 1; height: 1px; background: rgb(255 255 255 / 9%); }
    :host(.rail) .group:first-child .group-label { display: none; }
    :host(.rail) .item { justify-content: center; padding-inline: 0; }
    :host(.rail) .nav-foot { flex-direction: column; }
    :host(.rail) .company { flex: none; }

    /* Top bar */
    .body { display: flex; flex-direction: column; min-width: 0; }
    .bar { position: sticky; inset-block-start: 0; z-index: 5; display: flex; align-items: center; gap: 6px; height: var(--dw-shell-bar); padding-inline: var(--dw-5) var(--dw-4);
      background: rgb(255 255 255 / 88%); backdrop-filter: saturate(1.4) blur(8px); border-block-end: 1px solid var(--dw-line); }
    .menu { display: none; }
    .crumbs { display: flex; align-items: center; gap: 2px; min-width: 0; font-size: 12.5px; color: var(--dw-ink-3); white-space: nowrap; }
    .crumbs dw-icon { font-size: 16px; color: var(--dw-line-strong); }
    .crumbs strong { font-weight: 550; color: var(--dw-ink); overflow: hidden; text-overflow: ellipsis; }
    .bell { position: relative; }
    .bell-count { position: absolute; inset-block-start: 2px; inset-inline-end: 1px; pointer-events: none; }
    .user { all: unset; display: inline-flex; align-items: center; gap: 8px; height: 32px; padding-inline: 4px 6px; margin-inline-start: 4px; border-radius: var(--dw-radius); cursor: pointer;
      font-size: 13px; font-weight: 550; color: var(--dw-ink); }
    .user:hover { background: var(--dw-surface-3); }
    .user:focus-visible { outline: 2px solid var(--dw-focus); outline-offset: 1px; }
    .user dw-icon { font-size: 16px; color: var(--dw-ink-3); }
    .avatar { display: inline-grid; place-items: center; width: 24px; height: 24px; border-radius: 50%; background: var(--dw-primary); color: #fff; font-size: 10.5px; font-weight: 650; letter-spacing: 0.02em; }
    .content { flex: 1; padding: var(--dw-5); max-width: 1520px; width: 100%; margin-inline: auto; outline: none; }
    .scrim { display: none; }

    /* Notifications */
    .notif-head { display: flex; align-items: center; min-height: 42px; padding: 4px 8px 4px 14px; border-block-end: 1px solid var(--dw-line); }
    .notif-list { max-height: min(420px, 60vh); overflow-y: auto; }
    .notif { all: unset; box-sizing: border-box; display: block; width: 100%; padding: 9px 14px 9px 12px; border-inline-start: 2px solid transparent; border-block-end: 1px solid var(--dw-line-soft); cursor: pointer; }
    .notif:hover, .notif:focus-visible { background: var(--dw-surface-2); }
    .notif.unread { border-inline-start-color: var(--dw-primary); background: var(--dw-primary-soft); }
    .notif-title { display: block; font-weight: 600; font-size: 13px; line-height: 18px; color: var(--dw-ink); }
    .notif-body { display: block; font-size: 12.5px; line-height: 17px; color: var(--dw-ink-2); }
    .notif-date { display: block; margin-block-start: 2px; font-size: 11px; color: var(--dw-ink-3); }
    .notif-empty { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 28px 16px; font-size: 13px; color: var(--dw-ink-3); }
    .notif-empty dw-icon { font-size: 24px; }
    .notif-all { display: block; padding: 10px 14px; font-size: 12.5px; font-weight: 550; text-align: center; border-block-start: 1px solid var(--dw-line); }

    @media (max-width: 960px) {
      :host, :host(.rail) { grid-template-columns: minmax(0, 1fr); }
      .nav { position: fixed; inset-block: 0; inset-inline-start: 0; width: 264px; z-index: 20; transform: translateX(-100%); transition: transform 160ms; }
      :host-context([dir='rtl']) .nav { transform: translateX(100%); }
      .nav.open { transform: none; box-shadow: var(--dw-shadow-modal); }
      :host(.rail) .brand-name, :host(.rail) .item-label { display: initial; }
      :host(.rail) .company-text { display: flex; }
      :host(.rail) .group-label span { display: inline; }
      :host(.rail) .group-label::before { display: none; }
      :host(.rail) .group:first-child .group-label { display: flex; }
      :host(.rail) .item { justify-content: flex-start; padding-inline: 9px; }
      :host(.rail) .nav-foot { flex-direction: row; }
      .collapse { display: none; }
      .scrim { display: block; position: fixed; inset: 0; z-index: 15; border: 0; background: rgb(13 20 27 / 45%); }
      .menu { display: inline-flex; }
      .bar { padding-inline: var(--dw-2) var(--dw-3); }
      .user-name { display: none; }
      .content { padding: var(--dw-4) var(--dw-3); }
    }
  `,
})
export class AppShellComponent implements OnDestroy {
  protected readonly session = inject(Session);
  private readonly api = inject(Api);
  private readonly router = inject(Router);
  private readonly toast = inject(Toast);

  protected readonly navOpen = signal(false);
  protected readonly collapsed = signal(readRail());
  protected readonly notifications = signal<AppNotification[]>([]);
  protected readonly unread = signal(0);

  protected readonly groups = computed(() => navigationFor(this.session));
  protected readonly initials = computed(() => `${this.session.me()?.first_name?.[0] ?? ''}${this.session.me()?.last_name?.[0] ?? ''}`.toUpperCase());
  protected readonly otherCompanies = computed(() =>
    (this.session.me()?.memberships ?? []).filter((membership) => membership.status === 'active' && membership.company !== this.session.company()?.ulid),
  );

  private readonly url = toSignal(this.router.events.pipe(filter((event) => event instanceof NavigationEnd), map(() => this.router.url), startWith(this.router.url)), { initialValue: this.router.url });
  /** Where the user is, in the words of the navigation: "Opérations › Lots". */
  protected readonly place = computed(() => {
    const url = this.url().split(/[?#]/)[0];
    for (const group of this.groups()) {
      const item = group.items.find((candidate) => url === candidate.path || url.startsWith(`${candidate.path}/`));
      if (item) {
        return { group: group.label, item: item.label };
      }
    }

    return null;
  });

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

  protected toggleRail(): void {
    this.collapsed.update((value) => !value);
    try {
      localStorage.setItem(RAIL_KEY, this.collapsed() ? '1' : '0');
    } catch {
      // Private browsing: the choice simply does not survive a reload.
    }
  }

  protected accountActions(): MenuAction[] {
    return [
      { label: this.toast.text('shell.profile'), icon: 'person', run: () => void this.router.navigateByUrl('/account/profile') },
      { label: this.toast.text('shell.security'), icon: 'lock', run: () => void this.router.navigateByUrl('/account/security') },
      ...(this.otherCompanies().length > 0 ? [{ separator: true }] : []),
      ...this.otherCompanies().map((membership) => ({ label: membership.legal_name, icon: 'swap_horiz', run: () => void this.switchCompany(membership.company) })),
      { separator: true },
      { label: this.toast.text('shell.logout'), icon: 'logout', run: () => void this.logout() },
    ];
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

function readRail(): boolean {
  try {
    return localStorage.getItem(RAIL_KEY) === '1';
  } catch {
    return false;
  }
}
