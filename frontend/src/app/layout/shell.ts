import { ChangeDetectionStrategy, Component, HostListener, OnDestroy, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { NavigationEnd, Router, RouterLink, RouterOutlet } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { ButtonDirective } from 'primeng/button';
import { Popover } from 'primeng/popover';
import { Tooltip } from 'primeng/tooltip';
import { filter, map, startWith } from 'rxjs';

import { Api } from '../core/api/api';
import { AppNotification } from '../core/api/models';
import { Session } from '../core/auth/session';
import { Toast } from '../core/feedback/toast';
import { NotificationParamsPipe } from '../core/format/notification-params';
import { DatePipe } from '../core/format/pipes';
import { IconComponent } from '../ui/icon';
import { LanguageComponent } from '../ui/language';
import { MenuAction, MenuComponent } from '../ui/menu';
import { GlobalSearchComponent } from './global-search';
import { SETTINGS_PATHS, navigationFor, placeOf } from './navigation';
import { DaviHintComponent } from './davi-hint';
import { DaviAvatarComponent } from '../ui/davi-avatar';
import { DaviPanelComponent } from './davi-panel';
import { Davi } from '../core/davi/davi';

const RAIL_KEY = 'dw.nav.collapsed';

/**
 * Frame of the desktop application: navigation by role (collapsible to an icon rail), top bar with the
 * position in the product, the notifications and the account menu. On narrow screens the navigation becomes
 * a drawer.
 */
@Component({
  selector: 'dw-app-shell',
  imports: [RouterOutlet, RouterLink, GlobalSearchComponent, DaviPanelComponent, DaviHintComponent, DaviAvatarComponent, TranslocoDirective, DatePipe, NotificationParamsPipe, IconComponent, ButtonDirective, MenuComponent, Tooltip, Popover, LanguageComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '[class.rail]': 'collapsed()', '[class.platform]': "session.audience() === 'platform'" },
  template: `
    <ng-container *transloco="let t">
      <a class="skip" href="#main">{{ t('shell.skip') }}</a>

      <nav class="nav" [class.open]="navOpen()" [attr.aria-label]="t('shell.navigation')">
        <a class="brand" [routerLink]="session.home()">
          <span class="logo"><img src="icons/icon.svg" alt="" width="22" height="22" /></span>
          <span class="brand-name">DivaWaste@if (session.audience() === 'platform') {<small>{{ t('shell.backOffice') }}</small>}</span>
        </a>
        <div class="groups dw-scroll-dark">
          @for (group of groups(); track group.label) {
            <div class="group">
              @if (group.label) { <div class="group-label"><span>{{ t(group.label) }}</span></div> }
              @for (item of group.items; track item.label) {
                <a class="item" [routerLink]="item.path" [class.active]="place()?.item === item" (click)="navOpen.set(false)"
                  [attr.aria-current]="place()?.item === item ? 'page' : null"
                  [pTooltip]="t(item.label)" tooltipPosition="right" [tooltipDisabled]="!collapsed()" [showDelay]="150">
                  <dw-icon>{{ item.icon }}</dw-icon>
                  <span class="item-label">{{ t(item.label) }}</span>
                </a>
              }
            </div>
          }
        </div>
        <div class="nav-tools">
          @if (workshop()) {
            <a class="item" routerLink="/m" [pTooltip]="t('nav.workshop')" tooltipPosition="right" [tooltipDisabled]="!collapsed()" [showDelay]="150">
              <dw-icon>qr_code_scanner</dw-icon><span class="item-label">{{ t('nav.workshop') }}</span>
            </a>
          }
          @if (settings()) {
            <a class="item" routerLink="/settings" [class.active]="inSettings()" (click)="navOpen.set(false)" [attr.aria-current]="inSettings() ? 'page' : null"
              [pTooltip]="t('nav.settings')" tooltipPosition="right" [tooltipDisabled]="!collapsed()" [showDelay]="150">
              <dw-icon>settings</dw-icon><span class="item-label">{{ t('nav.settings') }}</span>
            </a>
          }
        </div>
        <div class="nav-foot">
          @if (session.audience() === 'platform') {
            <div class="company">
              <span class="company-mark" aria-hidden="true">D</span>
              <span class="company-text">
                <span class="company-label">{{ t('shell.operator') }}</span>
                <span class="company-name">Diva Software</span>
              </span>
            </div>
          } @else if (session.company(); as company) {
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
          <button pButton text severity="secondary" type="button" class="dw-icon-btn menu" (click)="navOpen.set(true)" [attr.aria-label]="t('shell.openNavigation')" [title]="t('shell.openNavigation')">
            <dw-icon>menu</dw-icon>
          </button>
          @if (session.audience() === 'industrial') {
            <dw-global-search class="search" />
          } @else if (place(); as here) {
            <div class="crumbs" aria-hidden="true"><strong>{{ t(here.item.label) }}</strong></div>
          }
          <span class="dw-spacer"></span>
          @if (session.audience() === 'platform') { <span class="space"><dw-icon>admin_panel_settings</dw-icon>{{ t('shell.platformSpace') }}</span> }

          <!-- DAVI, the assistant: beside every screen, Ctrl+J. -->
          @if (davi.available()) {
            <button type="button" class="davi-btn" [class.on]="davi.open()" (click)="davi.toggle()" [attr.aria-expanded]="davi.open()" [title]="t('davi.open') + ' (Ctrl+J)'">
              <dw-davi-avatar [size]="22" [active]="davi.busy()" /><span>{{ t('davi.short') }}</span>
              @if (!davi.open() && davi.note()) { <i class="davi-dot" aria-hidden="true"></i> }
            </button>
          }
          @if (session.audience() !== 'platform') {
            <button pButton text severity="secondary" type="button" class="dw-icon-btn bell" (click)="loadNotifications(); notif.toggle($event)"
              [attr.aria-label]="t('shell.notifications', { count: unread() })" [pTooltip]="t('notifications.title')" tooltipPosition="bottom" [showDelay]="400" aria-haspopup="dialog">
              <dw-icon>notifications</dw-icon>
              @if (unread() > 0) { <span class="bell-count" aria-hidden="true">{{ unread() > 9 ? '9+' : unread() }}</span> }
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
                @for (group of notificationGroups(); track group.key) {
                  <button type="button" class="notif" [class.unread]="group.unread > 0" (click)="openGroup(group); notif.hide()">
                    <span class="notif-title">{{ t('notifications.types.' + group.latest.type + '.title', (group.latest.params | dwNotificationParams)) }}
                      @if (group.items.length > 1) { <span class="notif-more">{{ t('notifications.more', { count: group.items.length - 1 }) }}</span> }
                    </span>
                    <span class="notif-body">{{ t('notifications.types.' + group.latest.type + '.body', (group.latest.params | dwNotificationParams)) }}</span>
                    <span class="notif-date">{{ group.latest.created_at | dwDate: 'datetime' }}</span>
                  </button>
                } @empty {
                  <div class="notif-empty"><dw-icon>notifications_none</dw-icon>{{ t('notifications.empty') }}</div>
                }
              </div>
              <a routerLink="/account/notifications" class="notif-all" (click)="notif.hide()">{{ t('notifications.all') }}</a>
            </p-popover>
          }

          <dw-language />

          <button type="button" class="user" (click)="userMenu.open($event, accountActions())" aria-haspopup="menu">
            <span class="avatar" aria-hidden="true">{{ initials() }}</span>
            <span class="user-name">{{ session.me()?.first_name }} {{ session.me()?.last_name }}</span>
            <dw-icon>expand_more</dw-icon>
          </button>
          <dw-menu #userMenu />
        </header>

        @if (tabs(); as section) {
          <div class="tabs" role="navigation" [attr.aria-label]="t(section.item.label)">
            <div class="tabs-inner">
              <span class="tabs-title">{{ t(section.item.label) }}</span>
              <div class="tab-list">
                @for (tab of section.item.tabs; track tab.path) {
                  <a class="tab" [routerLink]="tab.path" [class.active]="section.tab === tab" [attr.aria-current]="section.tab === tab ? 'page' : null">{{ t(tab.label) }}</a>
                }
              </div>
            </div>
          </div>
        } @else if (inSettings() && path() !== '/settings') {
          <!-- A settings page leads back to the list of settings. -->
          <div class="tabs" role="navigation" [attr.aria-label]="t('nav.settings')">
            <div class="tabs-inner">
              <a class="back" routerLink="/settings"><dw-icon aria-hidden="true">arrow_back</dw-icon>{{ t('nav.settings') }}</a>
            </div>
          </div>
        }

        <main id="main" class="content" tabindex="-1">
          <!-- PRD-02: production data of an ERP company come from the ERP; corrected here only on purpose. -->
          @if (session.withErp() && inProduction()) {
            <div class="erp" role="note">
              <dw-icon aria-hidden="true">hub</dw-icon>
              <span class="dw-spacer">{{ t(session.erpUnlocked() ? 'shell.erpUnlocked' : 'shell.erpNote') }}</span>
              @if (session.can('company.update') || session.erpUnlocked() || session.canAnywhere('production.view')) {
                <button pButton text size="small" type="button" (click)="session.erpUnlocked.set(!session.erpUnlocked())">{{ t(session.erpUnlocked() ? 'shell.erpLock' : 'shell.erpUnlock') }}</button>
              }
            </div>
          }
          <!-- DAVI's card for a page without a title component (the others show it under their title). -->
          <dw-davi-hint [fallback]="true" />
          <router-outlet />
        </main>
        <dw-davi-panel />
      </div>
    </ng-container>
  `,
  styles: `
    :host { display: grid; grid-template-columns: var(--dw-shell-nav) minmax(0, 1fr); min-height: 100dvh; transition: grid-template-columns 160ms var(--dw-ease); }
    :host(.rail) { grid-template-columns: var(--dw-shell-rail) minmax(0, 1fr); }
    .skip { position: absolute; inset-inline-start: -999px; }
    .skip:focus { inset-inline-start: 8px; inset-block-start: 8px; z-index: 30; background: var(--dw-surface); padding: 8px 12px; border-radius: var(--dw-radius); }

    /* Navigation: deep teal-black, a faint light from the top, the active section lit in the brand's glow. */
    .nav { position: sticky; inset-block-start: 0; height: 100dvh; display: flex; flex-direction: column; min-width: 0; overflow: hidden; color: var(--dw-nav-ink);
      background: radial-gradient(120% 60% at 0% 0%, rgb(127 214 194 / 9%), transparent 60%), linear-gradient(180deg, var(--dw-nav-2), var(--dw-nav) 46%); }
    .brand { display: flex; align-items: center; gap: 11px; flex: none; height: var(--dw-shell-bar); padding-inline: 16px; color: #fff; }
    .brand:hover { text-decoration: none; }
    .logo { display: grid; place-items: center; flex: none; width: 32px; height: 32px; border-radius: 9px; background: linear-gradient(145deg, rgb(127 214 194 / 22%), rgb(127 214 194 / 6%));
      box-shadow: inset 0 0 0 1px rgb(127 214 194 / 22%); }
    .brand-name { display: flex; align-items: baseline; gap: 6px; font-size: 15.5px; font-weight: 680; letter-spacing: -0.02em; white-space: nowrap; }
    .brand-name small { font-size: 11px; font-weight: 550; letter-spacing: 0.02em; color: var(--dw-nav-faint); }
    .groups { flex: 1; overflow-x: hidden; overflow-y: auto; padding: 10px 12px 12px; }
    .group + .group { margin-block-start: 18px; }
    .group-label { display: flex; align-items: center; height: 26px; padding-inline: 10px; font-size: 10.5px; font-weight: 650; letter-spacing: 0.09em; text-transform: uppercase; color: var(--dw-nav-faint); white-space: nowrap; }
    .item { position: relative; display: flex; align-items: center; gap: 12px; height: 38px; padding-inline: 10px; margin-block: 2px; border-radius: 9px; color: var(--dw-nav-ink); font-size: 13.5px;
      font-weight: 520; white-space: nowrap; transition: background-color 140ms, color 140ms; }
    .item dw-icon { font-size: 19px; color: #8aa19e; transition: color 140ms; }
    .item:hover { background: rgb(255 255 255 / 5%); text-decoration: none; color: #fff; }
    .item:hover dw-icon { color: #cfe0de; }
    .item.active { background: linear-gradient(90deg, rgb(127 214 194 / 16%), rgb(127 214 194 / 6%)); color: #fff; font-weight: 600; box-shadow: inset 0 0 0 1px rgb(127 214 194 / 14%); }
    .item.active dw-icon { color: var(--dw-nav-glow); }
    .item:focus-visible, .collapse:focus-visible { outline: 2px solid var(--dw-nav-glow); outline-offset: -2px; }
    .item-label { overflow: hidden; text-overflow: ellipsis; }
    .nav-tools { flex: none; padding: 6px 12px; border-block-start: 1px solid rgb(255 255 255 / 6%); }
    .nav-tools:empty { display: none; }

    .nav-foot { flex: none; display: flex; align-items: center; gap: 6px; padding: 10px 12px; border-block-start: 1px solid rgb(255 255 255 / 6%); }
    .company { display: flex; align-items: center; gap: 10px; flex: 1 1 auto; min-width: 0; padding: 4px; }
    .company-mark { flex: none; display: grid; place-items: center; width: 30px; height: 30px; border-radius: 8px; background: rgb(127 214 194 / 15%); color: #a8e6d8; font-size: 12.5px; font-weight: 700; text-transform: uppercase; }
    .company-text { display: flex; flex-direction: column; min-width: 0; }
    .company-label { font-size: 10.5px; line-height: 13px; color: var(--dw-nav-faint); }
    .company-name { color: #fff; font-size: 12.5px; line-height: 17px; font-weight: 580; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .collapse { all: unset; flex: none; display: grid; place-items: center; width: 30px; height: 30px; border-radius: 8px; cursor: pointer; color: var(--dw-nav-faint); }
    .collapse:hover { background: rgb(255 255 255 / 7%); color: #fff; }
    .collapse dw-icon { font-size: 18px; }

    /* Back office: another colour, so nobody mistakes it for a client workspace */
    :host(.platform) .nav { background: radial-gradient(120% 60% at 0% 0%, rgb(140 155 255 / 12%), transparent 60%), linear-gradient(180deg, #181c33, #12152a 46%); }
    :host(.platform) .item.active { background: linear-gradient(90deg, rgb(140 155 255 / 18%), rgb(140 155 255 / 6%)); box-shadow: inset 0 0 0 1px rgb(140 155 255 / 16%); }
    :host(.platform) .item.active dw-icon, :host(.platform) .brand-name small { color: #a5b0ff; }
    :host(.platform) .company-mark { background: rgb(140 155 255 / 18%); color: #c3caff; }
    :host(.platform) .avatar { background: #3b448f; }
    .space { display: inline-flex; align-items: center; gap: 6px; height: 26px; padding-inline: 8px 10px; margin-inline-end: 6px; border-radius: 13px; background: #eceefb; color: #3b448f;
      font-size: 12px; font-weight: 600; white-space: nowrap; }
    .space dw-icon { font-size: 15px; }

    /* Icon rail */
    :host(.rail) .brand { padding-inline: 16px; }
    :host(.rail) .brand-name, :host(.rail) .item-label, :host(.rail) .company-text { display: none; }
    :host(.rail) .group-label { padding-inline: 10px; }
    :host(.rail) .group-label span { display: none; }
    :host(.rail) .group-label::before { content: ''; flex: 1; height: 1px; background: rgb(255 255 255 / 9%); }
    :host(.rail) .item { justify-content: center; padding-inline: 0; }
    :host(.rail) .nav-foot { flex-direction: column; }
    :host(.rail) .company { flex: none; }

    /* Top bar: glass over the page */
    .body { display: flex; flex-direction: column; min-width: 0; }
    .bar { position: sticky; inset-block-start: 0; z-index: 6; display: flex; align-items: center; gap: 8px; height: var(--dw-shell-bar); padding-inline: 28px 18px;
      background: rgb(255 255 255 / 82%); backdrop-filter: saturate(1.6) blur(12px); border-block-end: 1px solid rgb(13 20 27 / 6%); }
    .menu { display: none; }
    .search { flex: 0 1 440px; }
    .crumbs { display: flex; align-items: center; gap: 2px; min-width: 0; font-size: 13px; color: var(--dw-ink-3); white-space: nowrap; }
    .crumbs strong { font-weight: 600; color: var(--dw-ink); overflow: hidden; text-overflow: ellipsis; }
    .davi-btn { all: unset; box-sizing: border-box; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; height: 34px; padding: 0 12px 0 10px; border-radius: 10px;
      font-size: 13px; font-weight: 650; letter-spacing: 0.03em; color: var(--dw-primary-strong); background: var(--dw-primary-soft); box-shadow: inset 0 0 0 1px rgb(15 92 86 / 14%);
      transition: background-color 140ms, box-shadow 140ms, color 140ms; }
    .davi-btn dw-icon { font-size: 18px; color: var(--dw-primary); }
    .davi-btn { position: relative; }
    .davi-dot { position: absolute; inset-block-start: 5px; inset-inline-end: 5px; width: 8px; height: 8px; border-radius: 50%; background: var(--dw-warning); box-shadow: 0 0 0 2px var(--dw-surface); }
    .davi-btn:hover { box-shadow: inset 0 0 0 1px var(--dw-primary); }
    .davi-btn.on { color: #fff; background: linear-gradient(135deg, var(--dw-primary), #3a9d8f); box-shadow: none; } .davi-btn.on dw-icon { color: #fff; }
    .davi-btn:focus-visible { outline: 2px solid var(--dw-focus); outline-offset: 2px; }
    @media (max-width: 640px) { .davi-btn span { display: none; } .davi-btn { padding: 0 8px; } .search { flex: 1 1 0; min-width: 0; } .bar { gap: 4px; } .user { margin-inline-start: 0; } }
    .bell { position: relative; }
    .bell-count { position: absolute; inset-block-start: 3px; inset-inline-end: 2px; min-width: 17px; height: 17px; padding-inline: 4px; border-radius: 9px; display: grid; place-items: center;
      background: var(--dw-danger); color: #fff; font-size: 10px; font-weight: 700; line-height: 1; box-shadow: 0 0 0 2px var(--dw-surface); pointer-events: none; }
    .user { all: unset; display: inline-flex; align-items: center; gap: 9px; height: 38px; padding-inline: 4px 8px; margin-inline-start: 4px; border-radius: 10px; cursor: pointer;
      font-size: 13.5px; font-weight: 580; color: var(--dw-ink); transition: background-color 140ms; }
    .user:hover { background: var(--dw-surface-3); }
    .user:focus-visible { outline: 2px solid var(--dw-focus); outline-offset: 1px; }
    .user dw-icon { font-size: 16px; color: var(--dw-ink-3); }
    .avatar { display: inline-grid; place-items: center; width: 30px; height: 30px; border-radius: 50%; background: linear-gradient(145deg, #176b64, #0c4a45); color: #fff; font-size: 11px;
      font-weight: 680; letter-spacing: 0.02em; box-shadow: 0 1px 2px rgb(10 60 56 / 30%); }

    /* Section tabs: the pages of the section, under the bar */
    .tabs { position: sticky; inset-block-start: var(--dw-shell-bar); z-index: 5; background: rgb(255 255 255 / 82%); backdrop-filter: saturate(1.6) blur(12px);
      border-block-end: 1px solid rgb(13 20 27 / 6%); }
    .tabs-inner { display: flex; align-items: center; gap: 20px; width: 100%; padding-inline: 32px; height: 48px; }
    .back { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; color: var(--dw-ink-2); text-decoration: none; padding: 6px 10px 6px 6px; margin-inline-start: -6px; border-radius: 8px; }
    .back:hover { color: var(--dw-primary); background: var(--dw-primary-soft); } .back dw-icon { font-size: 18px; }
    .tabs-title { flex: none; font-size: 13px; font-weight: 650; color: var(--dw-ink); padding-inline-end: 20px; border-inline-end: 1px solid var(--dw-line); line-height: 20px; }
    .tab-list { display: flex; align-items: stretch; gap: 4px; height: 100%; overflow-x: auto; scrollbar-width: none; }
    .tab { position: relative; display: flex; align-items: center; padding-inline: 12px; font-size: 13.5px; font-weight: 540; color: var(--dw-ink-2); white-space: nowrap; transition: color 140ms; }
    .tab:hover { color: var(--dw-ink); text-decoration: none; }
    .tab.active { color: var(--dw-primary); font-weight: 620; }
    .tab.active::after { content: ''; position: absolute; inset-inline: 10px; inset-block-end: -1px; height: 2px; border-radius: 2px 2px 0 0; background: var(--dw-primary); }

    .content { flex: 1; padding: 32px 32px 48px; width: 100%; outline: none; animation: dw-rise 220ms var(--dw-ease); }
    .scrim { display: none; }
    .erp { display: flex; align-items: center; gap: 10px; margin-block-end: 18px; padding: 10px 12px 10px 14px; border-radius: var(--dw-radius-lg); background: var(--dw-info-soft); color: var(--dw-ink); font-size: 13.5px; }
    .erp > dw-icon { color: var(--dw-info); }

    /* Notifications */
    .notif-head { display: flex; align-items: center; min-height: 48px; padding: 6px 10px 6px 16px; border-block-end: 1px solid var(--dw-line-soft); }
    .notif-list { max-height: min(440px, 62vh); overflow-y: auto; padding: 4px; }
    .notif { all: unset; box-sizing: border-box; display: block; width: 100%; padding: 10px 12px; border-radius: 8px; cursor: pointer; position: relative; }
    .notif + .notif { margin-block-start: 2px; }
    .notif:hover, .notif:focus-visible { background: var(--dw-surface-2); }
    .notif.unread { background: var(--dw-primary-soft); }
    .notif.unread::before { content: ''; position: absolute; inset-inline-start: 4px; inset-block-start: 16px; width: 5px; height: 5px; border-radius: 50%; background: var(--dw-primary); }
    .notif-title { display: block; font-weight: 620; font-size: 13px; line-height: 18px; color: var(--dw-ink); }
    .notif-more { margin-inline-start: 6px; font-size: 11px; font-weight: 600; color: var(--dw-primary); background: var(--dw-surface); border-radius: 999px; padding: 1px 7px; }
    .notif-body { display: block; font-size: 12.5px; line-height: 17px; color: var(--dw-ink-2); margin-block-start: 1px; }
    .notif-date { display: block; margin-block-start: 3px; font-size: 11px; color: var(--dw-ink-3); }
    .notif-empty { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 32px 16px; font-size: 13px; color: var(--dw-ink-3); }
    .notif-empty dw-icon { font-size: 26px; }
    .notif-all { display: block; padding: 12px 14px; font-size: 13px; font-weight: 580; text-align: center; border-block-start: 1px solid var(--dw-line-soft); }

    @media (max-width: 960px) {
      :host, :host(.rail) { grid-template-columns: minmax(0, 1fr); }
      .nav { position: fixed; inset-block: 0; inset-inline-start: 0; width: 272px; z-index: 20; transform: translateX(-100%); transition: transform 180ms var(--dw-ease); }
      :host-context([dir='rtl']) .nav { transform: translateX(100%); }
      .nav.open { transform: none; box-shadow: var(--dw-shadow-modal); }
      :host(.rail) .brand-name, :host(.rail) .item-label { display: initial; }
      :host(.rail) .company-text { display: flex; }
      :host(.rail) .group-label span { display: inline; }
      :host(.rail) .group-label::before { display: none; }
      :host(.rail) .item { justify-content: flex-start; padding-inline: 10px; }
      :host(.rail) .nav-foot { flex-direction: row; }
      .collapse { display: none; }
      .scrim { display: block; position: fixed; inset: 0; z-index: 15; border: 0; background: rgb(13 20 27 / 45%); backdrop-filter: blur(2px); }
      .menu { display: inline-flex; }
      .bar { padding-inline: 8px 12px; }
      .user-name, .space { display: none; }
      .tabs-inner { padding-inline: 16px; gap: 12px; }
      .tabs-title { display: none; }
      .content { padding: 20px 16px 32px; }
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
  protected readonly path = computed(() => this.url().split(/[?#]/)[0]);
  /** Where the user is, in the words of the navigation: the section and its tab. */
  protected readonly place = computed(() => placeOf(this.groups(), this.path()));
  /** The pages of the current section as tabs, when it has more than one. */
  protected readonly tabs = computed(() => {
    const place = this.place();
    return place && place.item.tabs.length > 1 ? place : null;
  });
  protected readonly inProduction = computed(() => this.path().startsWith('/production/') && this.path() !== '/production/import');
  protected readonly inSettings = computed(() => !this.place() && SETTINGS_PATHS.some((path) => this.path() === path || this.path().startsWith(`${path}/`)));
  /** Settings: anyone who may change part of the company's reference data or administration. */
  protected readonly settings = computed(() => this.session.audience() !== 'platform' && ['sites.view', 'waste_types.view', 'users.view', 'company.update', 'subscription.view', 'audit.view']
    .some((permission) => this.session.can(permission)));
  protected readonly workshop = computed(() => this.session.audience() === 'industrial' && this.session.uses('workshop') && this.session.can('app.mobile.access'));
  /** Notifications about one object, latest first, as one line (SH-02). */
  protected readonly notificationGroups = computed(() => {
    const groups = new Map<string, { key: string; latest: AppNotification; items: AppNotification[]; unread: number }>();
    for (const item of this.notifications()) {
      const key = item.link ?? item.id;
      const group = groups.get(key);
      if (group) {
        group.items.push(item);
        group.unread += item.read_at ? 0 : 1;
      } else {
        groups.set(key, { key, latest: item, items: [item], unread: item.read_at ? 0 : 1 });
      }
    }
    return [...groups.values()];
  });

  protected readonly davi = inject(Davi);
  private readonly poll = setInterval(() => this.loadNotifications(), 60_000);

  constructor() {
    this.loadNotifications();
    this.davi.check();
    // After each navigation, focus moves to the content so keyboard and screen-reader users land on the new page.
    this.router.events.pipe(filter((event) => event instanceof NavigationEnd)).subscribe(() => document.getElementById('main')?.focus({ preventScroll: true }));
  }

  @HostListener('document:keydown', ['$event'])
  protected shortcut(event: KeyboardEvent): void {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'j' && this.davi.available()) {
      event.preventDefault();
      this.davi.toggle();
    } else if (event.key === 'Escape' && this.davi.open()) {
      this.davi.open.set(false);
    }
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
        this.notifications.set(page.data.slice(0, 20));
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

  protected openGroup(group: { items: AppNotification[]; latest: AppNotification }): void {
    for (const item of group.items.filter((candidate) => !candidate.read_at && candidate !== group.latest)) {
      this.api.post(`me/notifications/${item.id}/read`).subscribe({ error: () => undefined });
    }
    this.open(group.latest);
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
      // The step-by-step guides (PDF), served by the web server next to the application.
      { label: this.toast.text('shell.guides'), icon: 'menu_book', run: () => void window.open('/guides/', '_blank', 'noopener') },
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
