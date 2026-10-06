import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormArray, FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { Tab, TabList, TabPanel, TabPanels, Tabs } from 'primeng/tabs';

import { Api } from '../../core/api/api';
import { Color, Localized, WasteType } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { ReferenceData } from '../../core/data/reference';
import { Locale } from '../../core/i18n/i18n';
import { Toast } from '../../core/feedback/toast';
import { CodePipe, LocalizedPipe } from '../../core/format/pipes';
import { Column, TABLE } from '../../ui/data-table';
import { ListStore } from '../../ui/list-store';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SearchComponent } from '../../ui/search';
import { StateComponent } from '../../ui/state';
import { CheckboxComponent } from '../../ui/checkbox';
import { DIALOG_PARTS, DialogRef, Dialogs, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { SELECT } from '../../ui/select';
import { FilterBarComponent } from '../../ui/filter-bar';

/** A colour of the company: its name and, optionally, the tint shown next to it. */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, ButtonDirective, InputText, ...FIELD, ...DIALOG_PARTS, CheckboxComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data ? 'colors.edit' : 'colors.new') }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="save()">
        <div class="dw-dialog-body">
          <dw-field [label]="t('fields.name')"><input pInputText formControlName="name" maxlength="60" /></dw-field>
          <dw-checkbox formControlName="tinted">{{ t('colors.withTint') }}</dw-checkbox>
          @if (form.controls.tinted.value) {
            <label class="tint"><input type="color" formControlName="hex_color" [attr.aria-label]="t('colors.tint')" /><span class="dw-mono">{{ form.controls.hex_color.value }}</span></label>
          }
        </div>
        <div class="dw-dialog-actions">
          <button pButton text type="button" dwDialogClose>{{ t('common.cancel') }}</button>
          <button pButton type="submit" [disabled]="busy()">{{ t('common.save') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: `
    .dw-dialog-body { display: grid; gap: var(--dw-3); min-width: min(380px, 80vw); }
    .tint { display: flex; align-items: center; gap: var(--dw-3); }
    .tint input { width: 56px; height: var(--dw-control); padding: 2px; border: 1px solid var(--dw-line); border-radius: var(--dw-radius); background: var(--dw-surface); cursor: pointer; }
  `,
})
export class ColorDialog {
  protected readonly data = dialogData<Color | null>();
  private readonly ref = inject(DialogRef);
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly locale = inject(Locale);

  protected readonly busy = signal(false);
  protected readonly form = inject(FormBuilder).nonNullable.group({
    name: [this.data ? this.locale.pick(this.data.name) : '', [Validators.required, Validators.maxLength(60)]],
    tinted: [this.data ? this.data.hex_color !== null : true],
    hex_color: [this.data?.hex_color ?? '#0F766E'],
  });

  protected async save(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const value = this.form.getRawValue();
    const body = { name: value.name.trim(), hex_color: value.tinted ? value.hex_color : null };
    try {
      this.ref.close(await firstValueFrom(this.data ? this.api.put<Color>(`colors/${this.data.code}`, body) : this.api.post<Color>('colors', body)));
      this.toast.success('colors.saved');
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }
}

interface CatalogItem {
  code: string;
  name: Localized;
  waste_family: string | null;
  regulatory_code: string | null;
  is_hazardous: boolean;
  treatment_channel: string | null;
  waste_type: string | null;
}

const DECIMAL = Validators.pattern(/^\d+([.,]\d{1,3})?$/);
const num = (value: string | null): string | null => (!value || value.trim() === '' ? null : value.replace(',', '.'));

/** M1-02 / M1-04: a company's own type, or the editable attributes of an existing one. */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, LocalizedPipe, IconComponent, ButtonDirective, InputText, ...FIELD, ...SELECT, CheckboxComponent, ...DIALOG_PARTS],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data ? 'wasteTypes.edit' : 'wasteTypes.new') }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          @if (data) { <p class="dw-muted note">{{ t('wasteTypes.frozen') }}</p> }
          <div class="dw-form">
            @if (!data) {
              <dw-field [label]="t('fields.code')">
                <input pInputText formControlName="code" maxlength="40" required />
              </dw-field>
              <dw-field [label]="t('wasteTypes.family')">
                <dw-select formControlName="waste_family" required>
                  @for (family of vocabulary()?.waste_families ?? []; track family.code) { <dw-option [value]="family.code" [label]="family.name | dwLocalized" /> }
                </dw-select>
              </dw-field>
            }
            <dw-field class="dw-span-2" [label]="t('fields.name')"><input pInputText formControlName="name" maxlength="150" required /></dw-field>
            @if (!data) {
              <dw-field [label]="t('lots.regulatoryCode')">
                <dw-select formControlName="regulatory_code">
                  <dw-option [value]="null" label="—" />
                  @for (code of vocabulary()?.regulatory_waste_codes ?? []; track code.code) {
                    <dw-option [value]="code.code" [label]="code.code + ' — ' + (code.label | dwLocalized) + (code.is_hazardous ? ' *' : '')" />
                  }
                </dw-select>
              </dw-field>
              <dw-checkbox formControlName="is_hazardous">{{ t('common.hazardous') }}</dw-checkbox>
            }
            <dw-field [label]="t('wasteTypes.unit')">
              <dw-select formControlName="unit" required>
                @for (unit of vocabulary()?.units ?? []; track unit.code) { <dw-option [value]="unit.code" [label]="(unit.name | dwLocalized) + ' (' + unit.symbol + ')'" /> }
              </dw-select>
            </dw-field>
            <dw-field [label]="t('wasteTypes.channel')">
              <dw-select formControlName="treatment_channel">
                <dw-option [value]="null" label="—" />
                @for (channel of vocabulary()?.treatment_channels ?? []; track channel.code) { <dw-option [value]="channel.code" [label]="channel.name | dwLocalized" /> }
              </dw-select>
            </dw-field>
            <dw-field [label]="t('lots.packaging')">
              <dw-select formControlName="packaging_type">
                <dw-option [value]="null" label="—" />
                @for (packaging of vocabulary()?.packaging_types ?? []; track packaging.code) { <dw-option [value]="packaging.code" [label]="packaging.name | dwLocalized" /> }
              </dw-select>
            </dw-field>
            <dw-field [label]="t('lots.color')">
              <dw-select formControlName="color_family">
                <dw-option [value]="null" label="—" />
                @for (color of vocabulary()?.color_families ?? []; track color.code) { <dw-option [value]="color.code" [label]="color.name | dwLocalized" /> }
              </dw-select>
            </dw-field>
            <dw-field [label]="t('lots.grammage')"><input pInputText formControlName="default_grammage_gsm" inputmode="decimal" /></dw-field>
            <dw-field [label]="t('wasteTypes.density')"><input pInputText formControlName="density_kg_m3" inputmode="decimal" /></dw-field>
            <dw-field [label]="t('wasteTypes.unitWeight')"><input pInputText formControlName="unit_weight_kg" inputmode="decimal" /></dw-field>

            <h3 class="dw-h3 dw-form-section">{{ t('wasteTypes.defaultComposition') }}</h3>
            <div class="dw-span-2 composition" formArrayName="composition">
              @for (group of composition.controls; track group; let index = $index) {
                <div class="line" [formGroupName]="index">
                  <dw-field [label]="t('wasteTypes.material')">
                    <dw-select formControlName="material" required>
                      @for (material of vocabulary()?.materials ?? []; track material.code) { <dw-option [value]="material.code" [label]="material.name | dwLocalized" /> }
                    </dw-select>
                  </dw-field>
                  <dw-field class="pct" [label]="t('wasteTypes.pct')"><input pInputText formControlName="pct" inputmode="decimal" required /></dw-field>
                  <button pButton text rounded severity="secondary" class="dw-icon-btn" type="button" (click)="composition.removeAt(index)" [attr.aria-label]="t('common.remove')"><dw-icon>close</dw-icon></button>
                </div>
              }
              <div class="dw-row">
                <button pButton severity="secondary" outlined type="button" (click)="addMaterial()"><dw-icon>add</dw-icon>{{ t('wasteTypes.addMaterial') }}</button>
                @if (composition.length > 0) { <span [class.bad]="total() !== 100" role="status">{{ t('wasteTypes.compositionTotal', { total: total() }) }}</span> }
              </div>
              @if (form.controls.composition.errors?.['server']; as message) { <p class="bad" role="alert">{{ message }}</p> }
            </div>
          </div>
        </div>
        <div class="dw-dialog-foot">
          <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
          <button pButton type="submit" [disabled]="busy() || (composition.length > 0 && total() !== 100)">{{ t('common.save') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: `
    .dw-dialog-body { min-width: min(640px, 84vw); } .note { margin-block-end: var(--dw-3); font-size: 13px; }
    .composition { display: grid; gap: var(--dw-1); } .line { display: flex; gap: var(--dw-2); align-items: center; } .pct { max-width: 110px; }
    .bad { color: var(--dw-danger); font-weight: 600; }
  `,
})
export class WasteTypeDialog {
  protected readonly data = dialogData<WasteType | null>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly reference = inject(ReferenceData);
  private readonly fb = inject(FormBuilder).nonNullable;
  private readonly ref = inject(DialogRef);

  protected readonly vocabulary = toSignal(this.reference.vocabulary());
  protected readonly busy = signal(false);
  protected readonly total = signal(0);
  protected readonly form = this.fb.group({
    code: [this.data?.code ?? '', [Validators.required, Validators.pattern(/^[A-Za-z0-9_-]+$/)]],
    name: [this.data?.name ?? '', Validators.required],
    waste_family: [this.data?.waste_family ?? '', Validators.required],
    regulatory_code: [this.data?.regulatory_code ?? null as string | null],
    is_hazardous: [this.data?.is_hazardous ?? false],
    unit: [this.data?.unit ?? 'kg', Validators.required],
    treatment_channel: [this.data?.treatment_channel ?? null as string | null],
    packaging_type: [this.data?.packaging_type ?? null as string | null],
    color_family: [this.data?.color_family ?? null as string | null],
    default_grammage_gsm: [this.data?.default_grammage_gsm ?? '', DECIMAL],
    density_kg_m3: [this.data?.density_kg_m3 ?? '', DECIMAL],
    unit_weight_kg: [this.data?.unit_weight_kg ?? '', DECIMAL],
    composition: this.fb.array((this.data?.default_composition ?? []).map((line) => this.materialGroup(line.material, String(Number(line.pct))))),
  });

  constructor() {
    this.form.controls.composition.valueChanges.subscribe(() => this.recompute());
    this.recompute();
  }

  protected get composition(): FormArray<FormGroup> {
    return this.form.controls.composition as FormArray<FormGroup>;
  }

  protected addMaterial(): void {
    this.composition.push(this.materialGroup('', ''));
  }

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const { composition, ...value } = this.form.getRawValue();
    const body = {
      ...value, default_grammage_gsm: num(value.default_grammage_gsm), density_kg_m3: num(value.density_kg_m3), unit_weight_kg: num(value.unit_weight_kg),
      default_composition: composition.length > 0 ? composition.map((line) => ({ material: line['material'] as string, pct: String(line['pct']).replace(',', '.') })) : null,
    };
    try {
      const type = await firstValueFrom(this.data ? this.api.put<WasteType>(`waste-types/${this.data.ulid}`, body) : this.api.post<WasteType>('waste-types', body));
      this.reference.refreshWasteTypes();
      this.toast.success('wasteTypes.saved');
      this.ref.close(type);
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }

  private materialGroup(material: string, pct: string): FormGroup {
    return this.fb.group({ material: [material, Validators.required], pct: [pct, [Validators.required, Validators.pattern(/^\d+([.,]\d{1,2})?$/)]] });
  }

  private recompute(): void {
    this.total.set(Math.round(this.form.controls.composition.getRawValue().reduce((sum, line) => sum + (Number(String(line['pct']).replace(',', '.')) || 0), 0) * 100) / 100);
  }
}

/** M1-02 / M1-03: the company's waste types and the reference catalogue they come from. */
@Component({
  imports: [
    CodePipe, TranslocoDirective, ...TABLE, PageHeaderComponent, StatusComponent, SearchComponent, StateComponent, LocalizedPipe, IconComponent, ButtonDirective, CheckboxComponent, FilterBarComponent, Tabs, TabList, Tab, TabPanels, TabPanel,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('wasteTypes.title')" [subtitle]="t('wasteTypes.subtitle')">
        @if (canManage) { <button pButton type="button" (click)="edit(null)"><dw-icon>add</dw-icon>{{ t('wasteTypes.new') }}</button> }
      </dw-page-header>

      <div class="dw-panel">
        <p-tabs [value]="0" (valueChange)="onTab($event)">
          <p-tablist>
            <p-tab [value]="0">{{ t('wasteTypes.mine') }}</p-tab>
            <p-tab [value]="1">{{ t('wasteTypes.catalog') }}</p-tab>
            <p-tab [value]="2">{{ t('colors.title') }}</p-tab>
          </p-tablist>
          <p-tabpanels>
            <p-tabpanel [value]="0">
              <dw-filter-bar [active]="(search() ? 1 : 0) + (inactive() ? 1 : 0)" (cleared)="search.set(''); inactive.set(false); list.reload()">
                <dw-search [placeholder]="t('common.search')" [query]="search()" (queryChange)="search.set($event); list.reload()" />
                <dw-checkbox [checked]="inactive()" (checkedChange)="inactive.set($event); list.reload()">{{ t('wasteTypes.showInactive') }}</dw-checkbox>
              </dw-filter-bar>
              <dw-data-table [columns]="columns" [rows]="list.rows()" [loading]="list.loading()" [error]="list.error()" [total]="list.total()" [pageIndex]="list.page() - 1" [pageSize]="list.perPage()"
                [clickable]="canManage" [emptyTitle]="t('wasteTypes.emptyTitle')" [emptyHint]="t('wasteTypes.emptyHint')" emptyIcon="category"
                (page)="list.setPage($event.pageIndex + 1, $event.pageSize)" (rowClick)="edit($event)" (retry)="list.reload()">
                <ng-template dwCell="code" let-type><span class="dw-mono">{{ type.code }}</span></ng-template>
                <ng-template dwCell="name" let-type>{{ type.name }} @if (type.is_hazardous) { <dw-status kind="alert" value="warning" [label]="t('common.hazardous')" /> }</ng-template>
                <ng-template dwCell="regulatory" let-type><span class="dw-mono">{{ type.regulatory_code ?? '—' }}</span></ng-template>
                <ng-template dwCell="channel" let-type>{{ type.treatment_channel ? t('channels.' + type.treatment_channel) : '—' }}</ng-template>
                <ng-template dwCell="composition" let-type>
                  @for (line of type.default_composition ?? []; track line.material) { <span class="chip">{{ line.material | dwCode: 'material' }} {{ +line.pct }} %</span> } @empty { — }
                </ng-template>
                <ng-template dwCell="status" let-type>
                  <dw-status kind="member" [value]="type.is_active ? 'active' : 'suspended'" [label]="t(type.is_active ? 'common.active' : 'common.inactive')" />
                </ng-template>
                <ng-template dwCell="actions" let-type>
                  @if (canManage) {
                    <button pButton text type="button" (click)="toggle(type); $event.stopPropagation()">{{ t(type.is_active ? 'common.deactivate' : 'common.activate') }}</button>
                  }
                </ng-template>
              </dw-data-table>
            </p-tabpanel>
            <p-tabpanel [value]="1">
              <dw-state [loading]="catalogLoading()" [error]="catalogError()" (retry)="loadCatalog(true)">
                <ul class="catalog">
                  @for (item of catalog(); track item.code) {
                    <li>
                      <div class="what">
                        <strong>{{ item.name | dwLocalized }}</strong>
                        @if (item.is_hazardous) { <dw-status kind="alert" value="warning" [label]="t('common.hazardous')" /> }
                        <span class="dw-faint dw-mono">{{ item.regulatory_code }}</span>
                        @if (item.treatment_channel) { <span class="dw-faint">· {{ t('channels.' + item.treatment_channel) }}</span> }
                      </div>
                      @if (item.waste_type) {
                        <dw-status kind="member" value="active" [label]="t('wasteTypes.activated')" />
                      } @else if (canManage) {
                        <button pButton severity="secondary" outlined type="button" [disabled]="activating() === item.code" (click)="activate(item)">{{ t('wasteTypes.activate') }}</button>
                      }
                    </li>
                  }
                </ul>
              </dw-state>
            </p-tabpanel>
            <p-tabpanel [value]="2">
              <div class="colors-head">
                <p class="dw-muted">{{ t('colors.hint') }}</p>
                @if (canManage) { <button pButton type="button" (click)="editColor(null)"><dw-icon>add</dw-icon>{{ t('colors.new') }}</button> }
              </div>
              <dw-state [loading]="colorsLoading()" [error]="colorsError()" (retry)="loadColors()">
                <ul class="catalog">
                  @for (color of colors(); track color.code) {
                    <li [class.off]="!color.is_active">
                      <span class="swatch" [style.background]="color.hex_color ?? 'transparent'" [class.none]="!color.hex_color" aria-hidden="true"></span>
                      <div class="what">
                        <strong>{{ color.name | dwLocalized }}</strong>
                        @if (!color.is_active) { <dw-status kind="member" value="suspended" [label]="t('common.inactive')" /> }
                      </div>
                      @if (!color.is_custom) {
                        <span class="dw-faint">{{ t('colors.default') }}</span>
                      } @else if (canManage) {
                        <button pButton text type="button" (click)="editColor(color)">{{ t('common.edit') }}</button>
                        <button pButton text type="button" (click)="toggleColor(color)">{{ t(color.is_active ? 'common.deactivate' : 'common.activate') }}</button>
                      } @else {
                        <span class="dw-faint">{{ t('colors.custom') }}</span>
                      }
                    </li>
                  }
                </ul>
              </dw-state>
            </p-tabpanel>
          </p-tabpanels>
        </p-tabs>
      </div>
    </ng-container>
  `,
  styles: `
    .chip { display: inline-block; padding: 1px 8px; margin-inline-end: 4px; border-radius: 999px; background: var(--dw-neutral-soft); font-size: 12px; }
    .catalog { list-style: none; margin: 0; padding: 0; }
    .catalog li { display: flex; align-items: center; gap: var(--dw-3); padding: 10px var(--dw-4); border-block-end: 1px solid var(--dw-line-soft); min-height: 52px; }
    .catalog li:last-child { border-block-end: 0; }
    .what { flex: 1; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .colors-head { display: flex; align-items: center; justify-content: space-between; gap: var(--dw-3); padding: var(--dw-3) var(--dw-4); border-block-end: 1px solid var(--dw-line-soft); }
    .colors-head p { margin: 0; }
    .swatch { flex: none; width: 22px; height: 22px; border-radius: 50%; border: 1px solid var(--dw-line); }
    .swatch.none { background: repeating-linear-gradient(45deg, var(--dw-surface-2) 0 4px, var(--dw-surface) 4px 8px) !important; }
    .catalog li.off strong { color: var(--dw-ink-3); }
  `,
})
export class WasteTypesPage {
  private readonly api = inject(Api);
  private readonly dialog = inject(Dialogs);
  private readonly toast = inject(Toast);
  private readonly reference = inject(ReferenceData);
  private readonly locale = inject(Locale);

  protected readonly canManage = inject(Session).can('waste_types.manage');
  protected readonly search = signal('');
  protected readonly inactive = signal(false);
  protected readonly list = new ListStore<WasteType>((query) => this.api.list<WasteType>('waste-types', { ...query, search: this.search(), active_only: !this.inactive() }));
  protected readonly catalog = signal<CatalogItem[]>([]);
  protected readonly catalogLoading = signal(false);
  protected readonly catalogError = signal<unknown>(null);
  protected readonly activating = signal<string | null>(null);
  protected readonly colors = signal<Color[]>([]);
  protected readonly colorsLoading = signal(false);
  protected readonly colorsError = signal<unknown>(null);
  private readonly catalogLoaded = computed(() => this.catalog().length > 0);

  protected readonly columns: Column<WasteType>[] = [
    { key: 'code', header: 'fields.code', width: '120px' },
    { key: 'name', header: 'fields.name' },
    { key: 'regulatory', header: 'lots.regulatoryCode', hideBelow: 900 },
    { key: 'channel', header: 'wasteTypes.channel', hideBelow: 900 },
    { key: 'composition', header: 'lots.composition', hideBelow: 1200 },
    { key: 'status', header: 'fields.status' },
    { key: 'actions', header: '', align: 'end' },
  ];

  protected onTab(tab: unknown): void {
    if (tab === 1) {
      this.loadCatalog();
    } else if (tab === 2 && this.colors().length === 0) {
      this.loadColors();
    }
  }

  protected loadColors(): void {
    this.colorsLoading.set(true);
    this.colorsError.set(null);
    this.api.get<Color[]>('colors').subscribe({
      next: (colors) => {
        this.colors.set(colors);
        this.colorsLoading.set(false);
      },
      error: (error: unknown) => {
        this.colorsError.set(error);
        this.colorsLoading.set(false);
      },
    });
  }

  protected editColor(color: Color | null): void {
    this.dialog.open<ColorDialog, Color | null, Color>(ColorDialog, { data: color }).afterClosed().subscribe((saved) => saved && this.colorsChanged());
  }

  /** Withdrawn colours are no longer offered; lots that already carry one keep it. */
  protected async toggleColor(color: Color): Promise<void> {
    try {
      await firstValueFrom(this.api.put(`colors/${color.code}`, { name: this.locale.pick(color.name), hex_color: color.hex_color, is_active: !color.is_active }));
      this.colorsChanged();
    } catch (error) {
      this.toast.error(error);
    }
  }

  private colorsChanged(): void {
    // The forms (lot, waste type) read colours from the shared vocabulary: drop it so they see the change.
    this.reference.refreshVocabulary();
    this.loadColors();
  }

  protected loadCatalog(force = false): void {
    if (this.catalogLoaded() && !force) {
      return;
    }
    this.catalogLoading.set(true);
    this.catalogError.set(null);
    this.api.get<CatalogItem[]>('waste-catalog').subscribe({
      next: (items) => {
        this.catalog.set(items);
        this.catalogLoading.set(false);
      },
      error: (error: unknown) => {
        this.catalogError.set(error);
        this.catalogLoading.set(false);
      },
    });
  }

  protected edit(type: WasteType | null): void {
    this.dialog.open<WasteTypeDialog, WasteType | null, WasteType>(WasteTypeDialog, { data: type }).afterClosed().subscribe((saved) => saved && this.list.reload());
  }

  /** Activation copies the catalogue defaults (code, hazard status, usual channel) into a type of the company. */
  protected async activate(item: CatalogItem): Promise<void> {
    this.activating.set(item.code);
    try {
      await firstValueFrom(this.api.post('waste-types', { catalog_item: item.code, code: item.code.toUpperCase().slice(0, 40) }));
      this.reference.refreshWasteTypes();
      this.toast.success('wasteTypes.saved');
      this.loadCatalog(true);
      this.list.reload();
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.activating.set(null);
    }
  }

  protected async toggle(type: WasteType): Promise<void> {
    try {
      await firstValueFrom(this.api.post(`waste-types/${type.ulid}/${type.is_active ? 'deactivate' : 'activate'}`));
      this.reference.refreshWasteTypes();
      this.list.reload();
    } catch (error) {
      this.toast.error(error);
    }
  }
}
