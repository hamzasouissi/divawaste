import { ChangeDetectionStrategy, Component, OnInit, computed, effect, inject, input, signal, untracked } from '@angular/core';
import { Router, RouterLink, RouterOutlet } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';

import { Api, ApiError, openBlob } from '../../core/api/api';
import { Lot, Pickup, Site, WasteType, Zone } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { DatePipe, KgPipe } from '../../core/format/pipes';
import { Offline, Operation } from '../../core/offline/offline';
import { Confirm } from '../../ui/confirm';
import { StatusComponent } from '../../ui/page';
import { StateComponent } from '../../ui/state';
import { QrScannerComponent } from './qr-scanner';
import { IconComponent } from '../../ui/icon';

/** Shared look of the workshop screens: one column, very large targets (gloves), no dense tables. */
const MOBILE_STYLES = `
  :host { display: grid; gap: var(--dw-4); }
  .title { font-size: 22px; line-height: 28px; font-weight: 600; }
  .tile { all: unset; box-sizing: border-box; cursor: pointer; display: flex; align-items: center; gap: 14px; width: 100%; min-height: var(--dw-touch); padding: 14px 18px;
    background: var(--dw-surface); border: 2px solid var(--dw-line); border-radius: 14px; font-size: 18px; font-weight: 600; }
  .tile:active { background: var(--dw-primary-soft); border-color: var(--dw-primary); }
  .tile:focus-visible { outline: 3px solid var(--dw-primary); outline-offset: 2px; }
  .tile.primary { background: var(--dw-primary); border-color: var(--dw-primary); color: #fff; min-height: 88px; font-size: 20px; }
  .tile.primary:active { background: var(--dw-primary-strong); }
  .tile dw-icon { flex: none; font-size: 30px; }
  .tile small { display: block; font-size: 13px; font-weight: 400; color: var(--dw-ink-2); }
  .tile.primary small { color: #cfe8e3; }
  .grid { display: grid; gap: var(--dw-3); }
  .big { min-height: var(--dw-touch); font-size: 17px; line-height: 22px; border-radius: 12px; gap: 10px; }
  .big dw-icon { font-size: 24px; }
  .muted { color: var(--dw-ink-2); font-size: 15px; }
`;

/** Frame of the workshop application: connection and synchronisation state are always in view. */
@Component({
  imports: [RouterOutlet, RouterLink, TranslocoDirective, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  // Same components as the desktop, at the size of a gloved thumb.
  host: { class: 'dw-touch-scale' },
  template: `
    <ng-container *transloco="let t">
      <header>
        <a routerLink="/m" class="home" [attr.aria-label]="t('mobile.home')"><img src="icons/icon.svg" alt="" width="32" height="32" /></a>
        <a routerLink="/m/sync" class="sync" [class]="state()" role="status">
          <dw-icon aria-hidden="true">{{ icon() }}</dw-icon>
          <span>
            <strong>{{ offline.online() ? t('mobile.online') : t('mobile.offline') }}</strong>
            <small>
              @if (offline.syncing()) { {{ t('mobile.syncing') }} }
              @else if (offline.problems() > 0) { {{ t('mobile.failed', { count: offline.problems() }) }} }
              @else if (offline.pending() > 0) { {{ t('mobile.pending', { count: offline.pending() }) }} }
              @else { {{ t('mobile.synced') }} }
            </small>
          </span>
        </a>
      </header>
      @if (offline.needsSignIn()) {
        <a class="signin" routerLink="/login" [queryParams]="{ next: '/m' }">{{ t('errors.UNAUTHENTICATED') }}</a>
      }
      <main><router-outlet /></main>
    </ng-container>
  `,
  styles: `
    :host { display: flex; flex-direction: column; min-height: 100dvh; background: var(--dw-canvas); }
    header { position: sticky; inset-block-start: 0; z-index: 5; display: flex; align-items: center; gap: 12px; padding: 10px 14px; padding-block-start: max(10px, env(safe-area-inset-top)); background: var(--dw-nav); }
    .home { display: grid; place-items: center; width: 48px; height: 48px; }
    .sync { flex: 1; display: flex; align-items: center; gap: 10px; min-height: 48px; padding: 6px 12px; border-radius: 12px; color: #fff; background: rgb(255 255 255 / 10%); }
    .sync:hover { text-decoration: none; }
    .sync strong { display: block; font-size: 15px; line-height: 18px; } .sync small { font-size: 12.5px; color: #cfe0dd; }
    .sync.offline { background: #7a4b00; } .sync.problem { background: #8c1d18; } .sync.pending { background: #1c4f7a; }
    .signin { display: block; padding: 12px 16px; background: var(--dw-danger); color: #fff; font-weight: 600; text-align: center; }
    main { flex: 1; width: min(100%, 560px); margin-inline: auto; padding: var(--dw-4); padding-block-end: max(var(--dw-5), env(safe-area-inset-bottom)); }
  `,
})
export class MobileShellComponent {
  protected readonly offline = inject(Offline);
  private readonly session = inject(Session);

  protected readonly state = computed(() => (!this.offline.online() ? 'offline' : this.offline.problems() > 0 ? 'problem' : this.offline.pending() > 0 ? 'pending' : 'ok'));
  protected readonly icon = computed(() => ({ offline: 'cloud_off', problem: 'sync_problem', pending: 'sync', ok: 'cloud_done' })[this.state()]);

  constructor() {
    const company = this.session.company();
    const me = this.session.me();
    if (company && me) {
      void this.offline.start(company.ulid, me.ulid);
    }
  }
}

@Component({
  imports: [RouterLink, TranslocoDirective, StatusComponent, DatePipe, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <div class="grid">
        <a class="tile primary" routerLink="/m/lots/new"><dw-icon aria-hidden="true">add_box</dw-icon><span>{{ t('mobile.newLot') }}</span></a>
        <a class="tile" routerLink="/m/scan"><dw-icon aria-hidden="true">qr_code_scanner</dw-icon><span>{{ t('mobile.scan') }}</span></a>
        @if (canLoad) { <a class="tile" routerLink="/m/loading"><dw-icon aria-hidden="true">local_shipping</dw-icon><span>{{ t('mobile.loadPickup') }}</span></a> }
      </div>

      @if (!offline.online()) { <p class="muted">{{ t('mobile.offlineHint') }}</p> }

      @if (recent().length > 0) {
        <section class="grid">
          <h2 class="dw-label">{{ t('mobile.recent') }}</h2>
          @for (operation of recent(); track operation.id) {
            <a class="tile" [routerLink]="operation.status === 'synced' && operation.entity ? ['/m/lots', operation.entity] : ['/m/sync']">
              <span class="dw-spacer">{{ operation.lot_number ?? operation.summary }}<small>@if (operation.lot_number) { {{ operation.summary }} · }{{ operation.occurred_at | dwDate: 'time' }}</small></span>
              <dw-status kind="sync" [value]="operation.status" />
            </a>
          }
        </section>
      }
      @if (hasDesktop) { <a routerLink="/dashboard" class="muted">{{ t('mobile.desktop') }}</a> }
    </ng-container>
  `,
  styles: MOBILE_STYLES,
})
export class MobileHomePage {
  protected readonly offline = inject(Offline);
  private readonly session = inject(Session);

  protected readonly canLoad = this.session.can('pickups.load');
  protected readonly hasDesktop = this.session.can('app.web.access');
  protected readonly recent = computed(() => this.offline.operations().filter((operation) => operation.type === 'lot.create').slice(0, 6));
}

type Step = 'site' | 'zone' | 'type' | 'weight' | 'done';

/**
 * M2-01: site → zone → waste type → weight, one decision per screen, no keyboard until the weight (entered on
 * a large on-screen keypad). Works identically offline: the lot is stored on the device and sent later.
 */
@Component({
  imports: [RouterLink, TranslocoDirective, StatusComponent, IconComponent, ButtonDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      @if (sites().length === 0) {
        <p class="muted" role="alert">{{ t('mobile.noSites') }}</p>
      } @else {
        @if (step() !== 'done') {
          <nav class="crumbs" [attr.aria-label]="t('mobile.newLot')">
            @if (sites().length > 1) { <button type="button" (click)="go('site')" [disabled]="!site()">{{ site()?.name ?? t('mobile.step.site') }}</button> }
            <button type="button" (click)="go('zone')" [disabled]="!zone()">{{ zone()?.name ?? t('mobile.step.zone') }}</button>
            <button type="button" (click)="go('type')" [disabled]="!type()">{{ type()?.name ?? t('mobile.step.type') }}</button>
          </nav>
        }

        @switch (step()) {
          @case ('site') {
            <h1 class="title">{{ t('mobile.chooseSite') }}</h1>
            <div class="grid">@for (item of sites(); track item.ulid) { <button type="button" class="tile" (click)="pickSite(item)">{{ item.name }}</button> }</div>
          }
          @case ('zone') {
            <h1 class="title">{{ t('mobile.chooseZone') }}</h1>
            <div class="grid">@for (item of site()?.zones ?? []; track item.ulid) { <button type="button" class="tile" (click)="pickZone(item)">{{ item.name }}</button> }</div>
          }
          @case ('type') {
            <h1 class="title">{{ t('mobile.chooseType') }}</h1>
            <div class="grid">
              @for (item of types(); track item.ulid) {
                <button type="button" class="tile" (click)="pickType(item)">
                  <span class="dw-spacer">{{ item.name }}</span>
                  @if (item.is_hazardous) { <dw-status kind="alert" value="warning" [label]="t('common.hazardous')" /> }
                </button>
              }
            </div>
          }
          @case ('weight') {
            <h1 class="title">{{ t('mobile.enterWeight') }}</h1>
            <output class="display" aria-live="polite">{{ weight() || '0' }} <small>kg</small></output>
            <div class="keypad">
              @for (key of keys; track key) { <button type="button" class="key" (click)="press(key)" [attr.aria-label]="key === '⌫' ? t('common.remove') : key">{{ key }}</button> }
            </div>
            <button pButton type="button" class="big" [disabled]="!valid() || busy()" (click)="create()"><dw-icon>check</dw-icon>{{ t('mobile.createLot') }}</button>
          }
          @case ('done') {
            @if (operation(); as op) {
              <div class="result" role="status">
                <dw-icon [class.ok]="op.status === 'synced'" aria-hidden="true">{{ op.status === 'synced' ? 'check_circle' : op.status === 'pending' ? 'schedule' : 'error' }}</dw-icon>
                @if (op.status === 'synced') {
                  <h1 class="title">{{ t('mobile.lotCreated') }}</h1>
                  <p class="number">{{ op.lot_number }}</p>
                } @else if (op.status === 'pending') {
                  <h1 class="title">{{ t('mobile.lotQueued') }}</h1>
                  <p class="muted">{{ t('mobile.lotQueuedHint') }}</p>
                } @else {
                  <h1 class="title">{{ t('status.sync.' + op.status) }}</h1>
                  <p class="muted">{{ op.error }}</p>
                }
                <p class="muted">{{ op.summary }}</p>
              </div>
              <div class="grid">
                @if (op.status === 'synced' && op.entity) {
                  <button pButton type="button" class="big" (click)="print(op.entity)"><dw-icon>print</dw-icon>{{ t('mobile.printLabel') }}</button>
                }
                <button pButton severity="secondary" outlined type="button" class="big" (click)="again()"><dw-icon>add</dw-icon>{{ t('mobile.another') }}</button>
                <a pButton text routerLink="/m" class="big">{{ t('mobile.home') }}</a>
              </div>
            }
          }
        }
      }
    </ng-container>
  `,
  styles: MOBILE_STYLES + `
    .crumbs { display: flex; gap: 8px; flex-wrap: wrap; }
    .crumbs button { all: unset; cursor: pointer; padding: 10px 14px; min-height: 28px; border-radius: 999px; background: var(--dw-primary-soft); color: var(--dw-primary-strong); font-weight: 600; font-size: 14px; }
    .crumbs button:disabled { background: var(--dw-neutral-soft); color: var(--dw-ink-3); cursor: default; }
    .crumbs button:focus-visible { outline: 3px solid var(--dw-primary); outline-offset: 2px; }
    .display { display: block; text-align: end; padding: 12px 18px; background: var(--dw-surface); border: 2px solid var(--dw-line); border-radius: 14px; font: 600 44px/52px var(--dw-font); font-variant-numeric: tabular-nums; }
    .display small { font-size: 20px; color: var(--dw-ink-2); }
    .keypad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
    .key { all: unset; cursor: pointer; display: grid; place-items: center; min-height: 68px; background: var(--dw-surface); border: 2px solid var(--dw-line); border-radius: 14px; font-size: 26px; font-weight: 600; }
    .key:active { background: var(--dw-primary-soft); border-color: var(--dw-primary); } .key:focus-visible { outline: 3px solid var(--dw-primary); outline-offset: 2px; }
    .result { display: grid; justify-items: center; gap: 6px; text-align: center; padding-block: var(--dw-4); }
    .result dw-icon { font-size: 64px; color: var(--dw-warning); } .result dw-icon.ok { color: var(--dw-success); }
    .number { font: 700 30px var(--dw-mono); }
  `,
})
export class MobileNewLotPage {
  private readonly offline = inject(Offline);
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);

  protected readonly keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9', ',', '0', '⌫'];
  protected readonly sites = computed(() => this.offline.bootstrap()?.sites ?? []);
  protected readonly types = computed(() => this.offline.bootstrap()?.waste_types ?? []);
  protected readonly site = signal<Site | null>(null);
  protected readonly zone = signal<Zone | null>(null);
  protected readonly type = signal<WasteType | null>(null);
  protected readonly weight = signal('');
  protected readonly step = signal<Step>('site');
  protected readonly busy = signal(false);
  private readonly operationId = signal<string | null>(null);
  /** Live view of the queued operation: the screen turns to "created" by itself once the server has answered. */
  protected readonly operation = computed<Operation | undefined>(() => (this.operationId() ? this.offline.operation(this.operationId() as string) : undefined));
  protected readonly valid = computed(() => /^\d{1,6}(,\d{1,3})?$/.test(this.weight()) && Number(this.weight().replace(',', '.')) > 0);

  constructor() {
    // A single site is the usual case for an operator: skip the question. The list may arrive after the screen
    // opens (first visit on this device), hence an effect and not a one-time check.
    effect(() => {
      const sites = this.sites();
      if (sites.length === 1 && untracked(() => this.site() === null)) {
        untracked(() => this.pickSite(sites[0]));
      }
    });
  }

  protected go(step: Step): void {
    this.step.set(step);
  }

  protected pickSite(site: Site): void {
    this.site.set(site);
    this.zone.set(null);
    const zones = site.zones ?? [];
    if (zones.length === 1) {
      this.pickZone(zones[0]);
    } else {
      this.step.set('zone');
    }
  }

  protected pickZone(zone: Zone): void {
    this.zone.set(zone);
    this.step.set('type');
  }

  protected pickType(type: WasteType): void {
    this.type.set(type);
    this.step.set('weight');
  }

  protected press(key: string): void {
    this.weight.update((value) => {
      if (key === '⌫') {
        return value.slice(0, -1);
      }
      if (key === ',') {
        return value.includes(',') || value === '' ? value : `${value},`;
      }
      const next = value === '0' ? key : value + key;
      const [whole, fraction] = next.split(',');

      return whole.length > 6 || (fraction?.length ?? 0) > 3 ? value : next;
    });
  }

  protected async create(): Promise<void> {
    const site = this.site();
    const zone = this.zone();
    const type = this.type();
    if (!site || !zone || !type || !this.valid()) {
      return;
    }
    this.busy.set(true);
    const operation = await this.offline.createLot(
      { site: site.ulid, zone: zone.ulid, waste_type: type.ulid, net_weight_kg: this.weight().replace(',', '.') },
      `${type.name} — ${this.weight()} kg — ${zone.name}`,
    );
    navigator.vibrate?.(80);
    this.operationId.set(operation.id);
    this.busy.set(false);
    this.step.set('done');
  }

  /** Same zone and type are kept: an operator usually weighs several bags of the same waste in a row. */
  protected again(): void {
    this.weight.set('');
    this.operationId.set(null);
    this.step.set('weight');
  }

  protected async print(lot: string): Promise<void> {
    try {
      openBlob(await firstValueFrom(this.api.blob(`lots/${lot}/label`)));
    } catch (error) {
      this.toast.error(error);
    }
  }
}

/** Scan a label to open its lot (M2-02). */
@Component({
  imports: [TranslocoDirective, QrScannerComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <h1 class="title">{{ t('mobile.scan') }}</h1>
      @if (missing()) { <p class="problem" role="alert">{{ t('mobile.scanNotFound') }}</p> }
      <dw-qr-scanner (scanned)="open($event)" />
    </ng-container>
  `,
  styles: MOBILE_STYLES + '.problem { color: var(--dw-danger); font-weight: 600; font-size: 16px; }',
})
export class MobileScanPage {
  private readonly router = inject(Router);
  protected readonly missing = signal(false);

  protected open(tag: string): void {
    void this.router.navigate(['/m/lots', tag.trim()]);
  }
}

@Component({
  imports: [RouterLink, TranslocoDirective, StatusComponent, StateComponent, DatePipe, KgPipe, IconComponent, ButtonDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-state [loading]="loading()" [error]="notFound() ? null : error()" (retry)="load()">
        @if (notFound()) {
          <p class="title">{{ t('mobile.scanNotFound') }}</p>
          <a pButton routerLink="/m/scan" class="big"><dw-icon>qr_code_scanner</dw-icon>{{ t('mobile.scan') }}</a>
        }
        @if (lot(); as l) {
          <div class="grid">
            <div class="card">
              <p class="number">{{ l.lot_number }}</p>
              <dw-status kind="lot" [value]="l.status" />
              <p class="what">{{ l.waste_type?.name }} @if (l.is_hazardous) { <dw-status kind="alert" value="warning" [label]="t('common.hazardous')" /> }</p>
              <p class="weight">{{ l.net_weight_kg | dwKg }}</p>
              <p class="muted">{{ l.site?.name }} · {{ l.zone?.name }}<br />{{ l.generated_at | dwDate: 'datetime' }}</p>
            </div>
            @if ((l.status === 'created') && canMove(l)) {
              <button pButton type="button" class="big" [disabled]="busy()" (click)="store(l)"><dw-icon>move_down</dw-icon>{{ t('mobile.storeLot') }}</button>
            }
            @if (canPrint(l)) {
              <button pButton severity="secondary" outlined type="button" class="big" (click)="print(l)"><dw-icon>print</dw-icon>{{ t('mobile.printLabel') }}</button>
            }
            <a pButton severity="secondary" outlined routerLink="/m/scan" class="big"><dw-icon>qr_code_scanner</dw-icon>{{ t('mobile.scan') }}</a>
          </div>
        }
      </dw-state>
    </ng-container>
  `,
  styles: MOBILE_STYLES + `
    .card { display: grid; gap: 6px; justify-items: start; padding: 18px; background: var(--dw-surface); border: 2px solid var(--dw-line); border-radius: 14px; }
    .number { font: 700 26px var(--dw-mono); } .what { font-size: 18px; font-weight: 600; } .weight { font-size: 32px; font-weight: 700; }
  `,
})
export class MobileLotPage implements OnInit {
  private readonly api = inject(Api);
  private readonly session = inject(Session);
  private readonly offline = inject(Offline);
  private readonly toast = inject(Toast);

  /** Either the lot ULID or the value of a scanned tag. */
  readonly tag = input.required<string>();
  protected readonly lot = signal<Lot | null>(null);
  protected readonly loading = signal(true);
  protected readonly busy = signal(false);
  protected readonly error = signal<unknown>(null);
  protected readonly notFound = signal(false);

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.notFound.set(false);
    this.api.get<Lot>(`lots/scan/${encodeURIComponent(this.tag())}`).subscribe({
      next: (lot) => {
        this.lot.set(lot);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.notFound.set(error instanceof ApiError && error.status === 404);
        this.error.set(error);
        this.loading.set(false);
      },
    });
  }

  protected canMove(lot: Lot): boolean {
    return this.session.can('lots.move', lot.site?.ulid);
  }

  protected canPrint(lot: Lot): boolean {
    return this.session.can('lots.print_label', lot.site?.ulid);
  }

  /** Recorded through the offline queue like every workshop action, then confirmed from the server state. */
  protected async store(lot: Lot): Promise<void> {
    this.busy.set(true);
    await this.offline.storeLot(lot, null);
    await this.offline.flush();
    this.busy.set(false);
    this.toast.success('lots.stored');
    this.load();
  }

  protected async print(lot: Lot): Promise<void> {
    try {
      openBlob(await firstValueFrom(this.api.blob(`lots/${lot.ulid}/label`)));
    } catch (error) {
      this.toast.error(error);
    }
  }
}

/** M4-04: loading a confirmed pickup by scanning each lot put on the truck. Needs the network (it commits the pickup). */
@Component({
  imports: [TranslocoDirective, StateComponent, QrScannerComponent, DatePipe, KgPipe, IconComponent, ButtonDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-state [loading]="loading()" [error]="error()" (retry)="load()">
        @if (pickup(); as p) {
          <h1 class="title">{{ t('mobile.loading.title', { number: p.pickup_number }) }}</h1>
          <p class="progress" role="status">{{ t('mobile.loading.scanned', { done: scanned().length, total: lines().length }) }}</p>
          @if (message(); as text) { <p class="notice" role="alert">{{ text }}</p> }
          <dw-qr-scanner (scanned)="scan($event)" />
          <ul class="lines">
            @for (line of lines(); track line.lot_number_snapshot) {
              <li [class.done]="scanned().includes(line.lot_number_snapshot)">
                <dw-icon aria-hidden="true">{{ scanned().includes(line.lot_number_snapshot) ? 'check_circle' : 'radio_button_unchecked' }}</dw-icon>
                <span class="dw-mono">{{ line.lot_number_snapshot }}</span><span class="dw-spacer">{{ line.waste_type_label_snapshot }}</span><span>{{ line.declared_weight_kg | dwKg }}</span>
              </li>
            }
          </ul>
          @if (scanned().length < lines().length) { <p class="muted">{{ t('mobile.loading.missing', { count: lines().length - scanned().length }) }}</p> }
          <button pButton type="button" class="big" [disabled]="scanned().length === 0 || busy()" (click)="confirm(p)"><dw-icon>check</dw-icon>{{ t('mobile.loading.confirm') }}</button>
          <button pButton text type="button" class="big" (click)="pickup.set(null)">{{ t('common.back') }}</button>
        } @else {
          <h1 class="title">{{ t('mobile.loadPickup') }}</h1>
          <div class="grid">
            @for (item of pickups(); track item.ulid) {
              <button type="button" class="tile" (click)="choose(item)">
                <span class="dw-spacer">{{ item.pickup_number }}<small>{{ item.provider?.legal_name }} · {{ item.confirmed_date | dwDate }} · {{ item.lots_count }} lots</small></span>
                <dw-icon aria-hidden="true">chevron_right</dw-icon>
              </button>
            } @empty {
              <p class="muted">{{ t('mobile.loading.none') }}</p>
            }
          </div>
        }
      </dw-state>
    </ng-container>
  `,
  styles: MOBILE_STYLES + `
    .progress { font-size: 18px; font-weight: 600; } .notice { padding: 10px 14px; border-radius: 12px; background: var(--dw-warning-soft); color: var(--dw-warning); font-weight: 600; }
    .lines { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
    .lines li { display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: var(--dw-surface); border: 1px solid var(--dw-line); border-radius: 10px; font-size: 14px; }
    .lines li.done { border-color: var(--dw-success); background: var(--dw-success-soft); } .lines li.done dw-icon { color: var(--dw-success); }
  `,
})
export class MobileLoadingPage {
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly confirmer = inject(Confirm);
  private readonly router = inject(Router);

  protected readonly pickups = signal<Pickup[]>([]);
  protected readonly pickup = signal<Pickup | null>(null);
  protected readonly scanned = signal<string[]>([]);
  protected readonly message = signal('');
  protected readonly loading = signal(true);
  protected readonly busy = signal(false);
  protected readonly error = signal<unknown>(null);
  protected readonly lines = computed(() => (this.pickup()?.lots ?? []).filter((line) => line.line_status === 'planned'));

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.list<Pickup>('pickups', { filter: { status: ['confirmed'] }, sort: 'requested_date', per_page: 50 }).subscribe({
      next: (page) => {
        this.pickups.set(page.data);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.error.set(error);
        this.loading.set(false);
      },
    });
  }

  protected async choose(pickup: Pickup): Promise<void> {
    try {
      this.pickup.set(await firstValueFrom(this.api.get<Pickup>(`pickups/${pickup.ulid}`)));
      this.scanned.set([]);
      this.message.set('');
    } catch (error) {
      this.toast.error(error);
    }
  }

  /** The label carries the tag value; the pickup lines hold the tag of each lot as it was when the pickup was requested. */
  protected scan(tag: string): void {
    const line = this.lines().find((item) => item.qr_tag_value_snapshot === tag.trim() || item.lot_number_snapshot === tag.trim().toUpperCase());
    if (!line) {
      this.message.set(this.toast.text('mobile.loading.notInPickup'));
      navigator.vibrate?.([120, 60, 120]);
    } else if (this.scanned().includes(line.lot_number_snapshot)) {
      this.message.set(this.toast.text('mobile.loading.already'));
    } else {
      this.message.set('');
      this.scanned.update((items) => [...items, line.lot_number_snapshot]);
    }
  }

  protected async confirm(pickup: Pickup): Promise<void> {
    const missing = this.lines().length - this.scanned().length;
    if (missing > 0 && !(await this.confirmer.ask({ title: this.toast.text('mobile.loading.confirm'), message: this.toast.text('mobile.loading.missing', { count: missing }) }))) {
      return;
    }
    this.busy.set(true);
    try {
      await firstValueFrom(this.api.post(`pickups/${pickup.ulid}/collect`, {
        lots: this.lines().filter((line) => this.scanned().includes(line.lot_number_snapshot)).map((line) => ({ lot: line.lot })),
      }));
      this.toast.success('pickups.done.collected');
      await this.router.navigate(['/m']);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}

/** What is waiting on the device and what the server refused: nothing recorded offline ever disappears silently. */
@Component({
  imports: [TranslocoDirective, StatusComponent, DatePipe, IconComponent, ButtonDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <h1 class="title">{{ t('mobile.queue') }}</h1>
      <p class="muted">{{ t('mobile.lastSync', { date: offline.lastSync() ? (offline.lastSync() | dwDate: 'datetime') : t('mobile.never') }) }}</p>
      <button pButton type="button" class="big" [disabled]="!offline.online() || offline.syncing() || offline.pending() === 0" (click)="offline.flush()">
        <dw-icon>sync</dw-icon>{{ offline.syncing() ? t('mobile.syncing') : t('mobile.syncNow') }}
      </button>
      <ul>
        @for (operation of offline.operations(); track operation.id) {
          <li>
            <div class="row"><strong class="dw-spacer">{{ operation.lot_number ?? operation.summary }}</strong><dw-status kind="sync" [value]="operation.status" /></div>
            <p class="muted">{{ operation.summary }} · {{ operation.occurred_at | dwDate: 'datetime' }}</p>
            @if (operation.error) { <p class="error">{{ operation.error }}</p> }
            @if (operation.status === 'failed' || operation.status === 'conflict') {
              <div class="row">
                @if (operation.status === 'failed') { <button pButton severity="secondary" outlined type="button" (click)="offline.retry(operation.id)">{{ t('mobile.retry') }}</button> }
                <button pButton text type="button" (click)="discard(operation.id)">{{ t('mobile.discard') }}</button>
              </div>
            }
          </li>
        } @empty {
          <li class="muted">{{ t('mobile.queueEmpty') }}</li>
        }
      </ul>
    </ng-container>
  `,
  styles: MOBILE_STYLES + `
    ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
    li { padding: 12px 14px; background: var(--dw-surface); border: 1px solid var(--dw-line); border-radius: 12px; display: grid; gap: 4px; }
    .row { display: flex; align-items: center; gap: 10px; } .error { color: var(--dw-danger); font-size: 14px; }
  `,
})
export class MobileSyncPage {
  protected readonly offline = inject(Offline);
  private readonly confirmer = inject(Confirm);
  private readonly toast = inject(Toast);

  protected async discard(id: string): Promise<void> {
    if (await this.confirmer.ask({ title: this.toast.text('mobile.discardConfirm'), danger: true })) {
      await this.offline.discard(id);
    }
  }
}
