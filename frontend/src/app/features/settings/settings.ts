import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { ToggleSwitch } from 'primeng/toggleswitch';
import { FormsModule } from '@angular/forms';
import { firstValueFrom, map } from 'rxjs';
import { toSignal } from '@angular/core/rxjs-interop';

import { Api } from '../../core/api/api';
import { Company, CompanyModule, CompanyProfileKey, Localized } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { ReferenceData } from '../../core/data/reference';
import { Toast } from '../../core/feedback/toast';
import { LocalizedPipe } from '../../core/format/pipes';
import { Dialogs } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { PageHeaderComponent } from '../../ui/page';
import { ColorsComponent } from '../catalog/colors';
import { TolerancesComponent } from '../catalog/tolerances';
import { PackagingVolumesDialog } from '../sites/sites';

interface SettingsCard {
  path: string;
  icon: string;
  /** Translation keys: settings.cards.<key>.title / .hint */
  key: string;
  permissions: string[];
  module?: CompanyModule;
  audience?: 'industrial' | 'provider';
}

const GROUPS: { label: string; cards: SettingsCard[] }[] = [
  {
    label: 'settings.groups.company',
    cards: [
      { path: '/admin/company', icon: 'business', key: 'company', permissions: ['company.update'] },
      { path: '/settings/modules', icon: 'tune', key: 'modules', permissions: ['company.update'], audience: 'industrial' },
      { path: '/admin/users', icon: 'group', key: 'users', permissions: ['users.view'] },
      { path: '/admin/subscription', icon: 'credit_card', key: 'subscription', permissions: ['subscription.view'] },
      { path: '/admin/audit', icon: 'history', key: 'audit', permissions: ['audit.view'] },
    ],
  },
  {
    label: 'settings.groups.reference',
    cards: [
      { path: '/sites', icon: 'factory', key: 'sites', permissions: ['sites.view'] },
      { path: '/waste-types', icon: 'category', key: 'wasteTypes', permissions: ['waste_types.view'] },
      { path: '/settings/reception', icon: 'scale', key: 'reception', permissions: ['waste_types.view'] },
      { path: '/settings/packaging', icon: 'inventory', key: 'packaging', permissions: ['sites.view'] },
      { path: '/settings/colors', icon: 'palette', key: 'colors', permissions: ['waste_types.view'], module: 'colors' },
    ],
  },
  {
    label: 'settings.groups.data',
    cards: [
      { path: '/production/import', icon: 'sync_alt', key: 'erp', permissions: ['production.manage'], module: 'production' },
      { path: '/settings/api-keys', icon: 'key', key: 'apiKeys', permissions: ['company.update'], audience: 'industrial' },
    ],
  },
];

/** Settings of the company under one gear (CO-02, G-05): each card leads to its screen, in plain words. */
@Component({
  imports: [TranslocoDirective, RouterLink, PageHeaderComponent, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('settings.title')" [subtitle]="t('settings.subtitle')" />
      <div class="groups">
        @for (group of groups(); track group.label) {
          <section>
            <h2 class="dw-label">{{ t(group.label) }}</h2>
            <div class="cards">
              @for (card of group.cards; track card.path) {
                <a class="card" [routerLink]="card.path">
                  <span class="ico"><dw-icon>{{ card.icon }}</dw-icon></span>
                  <span class="text"><strong>{{ t('settings.cards.' + card.key + '.title') }}</strong><span>{{ t('settings.cards.' + card.key + '.hint') }}</span></span>
                  <dw-icon class="go" aria-hidden="true">chevron_right</dw-icon>
                </a>
              }
            </div>
          </section>
        }
      </div>
    </ng-container>
  `,
  styles: `
    .groups { display: grid; gap: 32px; }
    section { display: grid; gap: 12px; }
    .cards { display: grid; gap: 14px; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); }
    .card { display: flex; align-items: center; gap: 14px; padding: 18px 16px 18px 18px; border-radius: var(--dw-radius-lg); background: var(--dw-surface); box-shadow: var(--dw-shadow);
      color: var(--dw-ink); transition: box-shadow 180ms var(--dw-ease), transform 180ms var(--dw-ease); }
    .card:hover { text-decoration: none; box-shadow: var(--dw-shadow-hover); transform: translateY(-1px); }
    .ico { display: grid; place-items: center; flex: none; width: 42px; height: 42px; border-radius: 11px; background: var(--dw-primary-soft); color: var(--dw-primary); }
    .ico dw-icon { font-size: 21px; }
    .text { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
    strong { font-size: 14.5px; font-weight: 620; letter-spacing: -0.01em; }
    .text span { font-size: 12.5px; line-height: 18px; color: var(--dw-ink-3); }
    .go { color: var(--dw-ink-3); font-size: 20px; transition: transform 180ms var(--dw-ease); }
    .card:hover .go { transform: translateX(2px); color: var(--dw-primary); }
  `,
})
export class SettingsPage {
  private readonly session = inject(Session);

  protected readonly groups = computed(() => {
    const audience = this.session.audience();
    return GROUPS.map((group) => ({
      label: group.label,
      cards: group.cards.filter((card) => (!card.audience || card.audience === audience) && (!card.module || this.session.uses(card.module))
        && card.permissions.some((permission) => this.session.can(permission))
        // A provider has no sites, waste types or production of its own here.
        && (audience === 'industrial' || ['company', 'users', 'subscription', 'audit'].includes(card.key))),
    })).filter((group) => group.cards.length > 0);
  });
}

const MODULE_ICONS: Record<CompanyModule, string> = {
  production: 'precision_manufacturing', production_finance: 'payments', marketplace: 'storefront', contracts: 'handshake',
  authorizations: 'gavel', colors: 'palette', workshop: 'qr_code_scanner',
};

/** The parts of DivaWaste the company uses, and how it works (G-02, G-03). Turning a module off only hides it. */
@Component({
  imports: [TranslocoDirective, FormsModule, PageHeaderComponent, IconComponent, ToggleSwitch],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('settings.modules.title')" [subtitle]="t('settings.modules.subtitle')" />
      <div class="dw-stack">
        <section class="dw-panel">
          <div class="dw-panel-head"><h2 class="dw-h2">{{ t('settings.modules.howTitle') }}</h2></div>
          <div class="dw-panel-body choice">
            @for (option of [true, false]; track option) {
              <button type="button" class="option" [class.on]="erp() === option" (click)="setErp(option)" [attr.aria-pressed]="erp() === option">
                <dw-icon>{{ option ? 'hub' : 'edit_note' }}</dw-icon>
                <strong>{{ t(option ? 'settings.modules.withErp' : 'settings.modules.withoutErp') }}</strong>
                <span>{{ t(option ? 'settings.modules.withErpHint' : 'settings.modules.withoutErpHint') }}</span>
              </button>
            }
          </div>
        </section>
        <section class="dw-panel">
          <div class="dw-panel-head"><h2 class="dw-h2">{{ t('settings.modules.listTitle') }}</h2></div>
          <ul class="modules">
            @for (module of modules; track module) {
              <li [class.off]="!value(module)">
                <span class="ico"><dw-icon>{{ icon(module) }}</dw-icon></span>
                <label class="text" [for]="'module-' + module"><strong>{{ t('settings.modules.items.' + module + '.title') }}</strong><span>{{ t('settings.modules.items.' + module + '.hint') }}</span></label>
                <p-toggleswitch [inputId]="'module-' + module" [ngModel]="value(module)" (ngModelChange)="toggle(module, $event)" [disabled]="busy() || (module === 'production_finance' && !value('production'))" />
              </li>
            }
          </ul>
        </section>
      </div>
    </ng-container>
  `,
  styles: `
    .choice { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
    .option { all: unset; box-sizing: border-box; display: grid; gap: 4px; padding: 18px; border-radius: 12px; cursor: pointer; border: 1px solid var(--dw-line); transition: border-color 140ms, box-shadow 140ms, background-color 140ms; }
    .option dw-icon { font-size: 22px; color: var(--dw-ink-3); margin-block-end: 6px; }
    .option strong { font-size: 14.5px; font-weight: 620; } .option span { font-size: 12.5px; color: var(--dw-ink-3); line-height: 18px; }
    .option:hover { border-color: var(--dw-line-strong); }
    .option.on { border-color: var(--dw-primary); background: var(--dw-primary-soft); box-shadow: 0 0 0 3px var(--dw-focus-soft); }
    .option.on dw-icon { color: var(--dw-primary); }
    .option:focus-visible { outline: 2px solid var(--dw-focus); outline-offset: 2px; }
    .modules { list-style: none; margin: 0; padding: 4px 0; }
    .modules li { display: flex; align-items: center; gap: 14px; padding: 14px 20px; border-block-end: 1px solid var(--dw-line-soft); transition: opacity 140ms; }
    .modules li:last-child { border-block-end: 0; }
    .modules li.off .ico { background: var(--dw-surface-3); color: var(--dw-ink-3); }
    .ico { display: grid; place-items: center; flex: none; width: 38px; height: 38px; border-radius: 10px; background: var(--dw-primary-soft); color: var(--dw-primary); }
    .text { display: flex; flex-direction: column; gap: 2px; flex: 1; min-width: 0; cursor: pointer; }
    .text strong { font-size: 14px; font-weight: 600; } .text span { font-size: 12.5px; color: var(--dw-ink-3); line-height: 18px; }
  `,
})
export class ModulesPage {
  private readonly api = inject(Api);
  private readonly session = inject(Session);
  private readonly toast = inject(Toast);

  protected readonly modules: CompanyModule[] = ['production', 'production_finance', 'marketplace', 'contracts', 'authorizations', 'colors', 'workshop'];
  protected readonly busy = signal(false);
  protected readonly erp = computed(() => this.session.company()?.profile?.erp ?? null);

  protected icon(module: CompanyModule): string {
    return MODULE_ICONS[module];
  }

  protected value(module: CompanyModule): boolean {
    return this.session.uses(module);
  }

  protected toggle(module: CompanyModule, on: boolean): void {
    void this.save({ modules: { [module]: on } });
  }

  protected setErp(erp: boolean): void {
    const profile = { ...(this.session.company()?.profile ?? { erp: false, production: false, hazardous: false, customs: false, marketplace: false }), erp };
    // Only the way of working changes here: the modules the company chose stay as they are.
    const modules = this.session.company()?.modules;
    void this.save({ profile, ...(modules ? { modules } : {}) });
  }

  private async save(body: { modules?: Partial<Record<CompanyModule, boolean>>; profile?: Record<CompanyProfileKey, boolean> }): Promise<void> {
    this.busy.set(true);
    try {
      const company = await firstValueFrom(this.api.put<Company>('company/modules', body));
      this.session.setCompany(company);
      this.toast.success('settings.modules.saved');
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}

/** Reception and gaps (G-05): the weight-gap limit of the company and the tolerance rules, in one place. */
@Component({
  imports: [TranslocoDirective, ReactiveFormsModule, PageHeaderComponent, ButtonDirective, InputText, ...FIELD, TolerancesComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('settings.reception.title')" [subtitle]="t('settings.reception.subtitle')" />
      <div class="dw-stack">
        @if (session.can('company.update')) {
          <form class="dw-panel" [formGroup]="form" (ngSubmit)="save()">
            <div class="dw-panel-head"><h2 class="dw-h2">{{ t('settings.reception.defaultTitle') }}</h2></div>
            <div class="dw-panel-body row">
              <dw-field [label]="t('admin.company.varianceThreshold')">
                <input pInputText inputmode="decimal" formControlName="weight_variance_threshold_pct" />
                <small dwHint>{{ t('admin.company.varianceHint') }}</small>
              </dw-field>
              <button pButton type="submit" [disabled]="busy() || form.pristine">{{ t('common.save') }}</button>
            </div>
          </form>
        }
        <section class="dw-panel"><dw-tolerances /></section>
      </div>
    </ng-container>
  `,
  styles: `.row { display: flex; align-items: flex-start; gap: 16px; flex-wrap: wrap; } .row dw-field { flex: 0 1 420px; } .row button { margin-block-start: 22px; }`,
})
export class ReceptionSettingsPage {
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  protected readonly session = inject(Session);
  protected readonly busy = signal(false);
  protected readonly form = inject(FormBuilder).nonNullable.group({
    weight_variance_threshold_pct: [this.session.company()?.weight_variance_threshold_pct ?? '5', [Validators.required, Validators.pattern(/^\d+([.,]\d{1,2})?$/)]],
  });

  protected async save(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    this.busy.set(true);
    try {
      const company = await firstValueFrom(this.api.put<Company>('company', { weight_variance_threshold_pct: this.form.getRawValue().weight_variance_threshold_pct.replace(',', '.') }));
      this.session.setCompany(company);
      this.form.markAsPristine();
      this.toast.success('common.saved');
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }
}

/** Colours of the company (G-05): a reference list used by lots, zones and raw materials. */
@Component({
  imports: [TranslocoDirective, PageHeaderComponent, ColorsComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('colors.title')" [subtitle]="t('settings.cards.colors.hint')" />
      <section class="dw-panel"><dw-colors /></section>
    </ng-container>
  `,
})
export class ColorsSettingsPage {}

/** The room one package takes once stored, per packaging (S-04): used to know how full a zone is. */
@Component({
  imports: [TranslocoDirective, PageHeaderComponent, ButtonDirective, IconComponent, LocalizedPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('sites.packagingVolumes')" [subtitle]="t('sites.packagingVolumesHint')">
        @if (session.can('sites.manage')) { <button pButton type="button" (click)="edit()"><dw-icon>edit</dw-icon>{{ t('common.edit') }}</button> }
      </dw-page-header>
      <section class="dw-panel">
        <table class="dw-table">
          <thead><tr><th>{{ t('lots.packaging') }}</th><th class="num">{{ t('settings.packaging.volume') }}</th></tr></thead>
          <tbody>
            @for (line of lines(); track line.packaging_type) {
              <tr><td>{{ name(line.packaging_type) | dwLocalized: line.packaging_type }}</td><td class="num">{{ line.volume_m3 === null ? t('settings.packaging.unknown') : (+line.volume_m3) + ' m³' }}</td></tr>
            }
          </tbody>
        </table>
      </section>
    </ng-container>
  `,
})
export class PackagingSettingsPage {
  private readonly api = inject(Api);
  private readonly dialog = inject(Dialogs);
  protected readonly session = inject(Session);
  private readonly names = toSignal(inject(ReferenceData).vocabulary().pipe(map((vocabulary) => new Map(vocabulary.packaging_types.map((type) => [type.code, type.name])))));
  protected readonly lines = signal<{ packaging_type: string; volume_m3: string | null }[]>([]);

  constructor() {
    this.load();
  }

  protected name(code: string): Localized | null {
    return this.names()?.get(code) ?? null;
  }

  protected edit(): void {
    this.dialog.open(PackagingVolumesDialog).afterClosed().subscribe((saved) => saved && this.load());
  }

  private load(): void {
    this.api.get<{ packaging_type: string; volume_m3: string | null }[]>('packaging-volumes').subscribe((lines) => this.lines.set(lines));
  }
}
