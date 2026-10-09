import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { Tab, TabList, TabPanel, TabPanels, Tabs } from 'primeng/tabs';
import { Textarea } from 'primeng/textarea';

import { Api } from '../../core/api/api';
import { Session } from '../../core/auth/session';
import { ReferenceData } from '../../core/data/reference';
import { Toast } from '../../core/feedback/toast';
import { CodePipe, LocalizedPipe, NumberPipe } from '../../core/format/pipes';
import { CheckboxComponent } from '../../ui/checkbox';
import { Column, TABLE } from '../../ui/data-table';
import { DIALOG_PARTS, DialogRef, Dialogs, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { FilterBarComponent } from '../../ui/filter-bar';
import { IconComponent } from '../../ui/icon';
import { ListStore } from '../../ui/list-store';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SearchComponent } from '../../ui/search';
import { SELECT } from '../../ui/select';
import { Article, DECIMAL, ProductionLists, RawMaterial, blankToNull, decimal } from './production-data';
import { HelpComponent } from '../../ui/help';
import { COLOR } from '../../ui/swatch';

/**
 * A raw material: who supplies it, how it is counted, what it is made of, and the deliveries received. Its
 * composition and colour are what the waste coming from it will carry.
 */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, LocalizedPipe, NumberPipe, ButtonDirective, InputText, Textarea, ...FIELD, ...SELECT, ...COLOR, ...DIALOG_PARTS, IconComponent, CheckboxComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data ? 'production.materials.edit' : 'production.materials.new') }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <div class="dw-form">
            <dw-field [label]="t('production.reference')"><input pInputText formControlName="reference" maxlength="60" required /></dw-field>
            <dw-field [label]="t('production.materials.unit')">
              <dw-select formControlName="unit" required>
                @for (unit of vocabulary()?.material_units ?? []; track unit.code) { <dw-option [value]="unit.code" [label]="(unit.name | dwLocalized) + ' (' + unit.symbol + ')'" /> }
              </dw-select>
            </dw-field>
            <dw-field class="dw-span-2" [label]="t('production.designation')"><input pInputText formControlName="name" maxlength="191" required /></dw-field>
            <dw-field [label]="t('production.material.category')"><input pInputText formControlName="category" maxlength="80" /></dw-field>
            <dw-field [label]="t('production.material.defaultSupplier')">
              <dw-select formControlName="supplier">
                <dw-option value="" label="—" />
                @for (supplier of suppliers(); track supplier.ulid) { <dw-option [value]="supplier.ulid" [label]="supplier.name" /> }
              </dw-select>
              <small dwHint>{{ t('production.material.defaultSupplierHint') }}</small>
            </dw-field>
            <dw-field [label]="t('lots.grammage')"><input pInputText formControlName="grammage_gsm" inputmode="decimal" /></dw-field>
            <dw-field [label]="t('production.materials.width')">
              <input pInputText formControlName="width_cm" inputmode="decimal" />
              <small dwHint>{{ t('production.materials.widthHint') }}</small>
            </dw-field>
            <dw-field [label]="t('lots.color')">
              <dw-select formControlName="color_family">
                <dw-option value="" label="—" />
                @for (color of vocabulary()?.color_families ?? []; track color.code) { <dw-option [value]="color.code" [label]="(color.name | dwLocalized) | dwColorName: color.pantone_reference" [swatch]="color.hex_color" /> }
              </dw-select>
            </dw-field>
            <dw-field [label]="t('production.materials.colorLabel')"><input pInputText formControlName="color_label" maxlength="100" placeholder="19-4052 TCX" /></dw-field>

            <section class="dw-span-2 part">
              <h3 class="dw-h3">{{ t('lots.composition') }}</h3>
              @for (line of composition.controls; track line; let i = $index) {
                <div class="line" [formGroup]="line">
                  <dw-select formControlName="material" [ariaLabel]="t('lots.composition')">
                    @for (fibre of vocabulary()?.materials ?? []; track fibre.code) { <dw-option [value]="fibre.code" [label]="fibre.name | dwLocalized" /> }
                  </dw-select>
                  <input pInputText formControlName="pct" inputmode="decimal" placeholder="%" [attr.aria-label]="'%'" />
                  <button pButton text rounded severity="secondary" class="dw-icon-btn" type="button" (click)="composition.removeAt(i)" [attr.aria-label]="t('common.remove')"><dw-icon>close</dw-icon></button>
                </div>
              }
              <button pButton text type="button" class="add" (click)="addFibre()"><dw-icon>add</dw-icon>{{ t('production.materials.addFibre') }}</button>
              @if (composition.length > 0 && total() !== 100) { <small class="dw-field-error" role="alert">{{ t('production.materials.total', { total: total() | dwNumber: 2 }) }}</small> }
            </section>

            <dw-field class="dw-span-2" [label]="t('fields.notes')"><textarea pTextarea formControlName="notes" rows="2" maxlength="1000"></textarea></dw-field>
            @if (data) { <dw-checkbox class="dw-span-2" formControlName="is_active">{{ t('common.active') }}</dw-checkbox> }
          </div>

        </div>
        <div class="dw-dialog-foot">
          <button pButton type="button" severity="secondary" outlined (click)="ref.close(changed())">{{ t('common.close') }}</button>
          <button pButton type="submit" [disabled]="busy()">{{ t('common.save') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: `
    .dw-dialog-body { inline-size: min(680px, 86vw); }
    .part { display: grid; gap: var(--dw-2); } .part p { margin: 0; font-size: 13px; }
    .batches { margin-block-start: var(--dw-4); padding-block-start: var(--dw-3); border-block-start: 1px solid var(--dw-line-soft); }
    .line { display: grid; grid-template-columns: minmax(0, 1fr) 110px auto; gap: var(--dw-2); align-items: center; }
    .line.batch { grid-template-columns: minmax(0, 1fr) 170px 120px auto; }
    .add { justify-self: start; }
  `,
})
export class MaterialDialog {
  protected readonly data = dialogData<RawMaterial | null>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  protected readonly ref = inject(DialogRef);
  private readonly fb = inject(FormBuilder);

  protected readonly vocabulary = toSignal(inject(ReferenceData).vocabulary());
  protected readonly suppliers = toSignal(inject(ProductionLists).suppliers(), { initialValue: [] });
  /** The saved material: deliveries can only be added to one that exists. */
  protected readonly material = signal<RawMaterial | null>(this.data);
  protected readonly changed = signal(false);
  protected readonly busy = signal(false);
  protected readonly composition = this.fb.array((this.data?.composition ?? []).map((line) => this.fibre(line.material, line.pct)));
  protected readonly form = this.fb.nonNullable.group({
    reference: [this.data?.reference ?? '', Validators.required],
    name: [this.data?.name ?? '', Validators.required],
    unit: [this.data?.unit ?? 'm', Validators.required],
    category: [this.data?.category ?? ''],
    supplier: [this.data?.supplier?.ulid ?? ''],
    color_family: [this.data?.color_family ?? ''],
    color_label: [this.data?.color_label ?? ''],
    grammage_gsm: [this.data?.grammage_gsm ?? '', DECIMAL],
    width_cm: [this.data?.width_cm ?? '', DECIMAL],
    notes: [this.data?.notes ?? ''],
    is_active: [this.data?.is_active ?? true],
    composition: this.composition,
  });
  private readonly lines = toSignal(this.composition.valueChanges, { initialValue: this.composition.getRawValue() });

  protected total(): number {
    return Math.round(this.lines().reduce((sum, line) => sum + Number(String(line['pct'] ?? '').replace(',', '.') || 0), 0) * 100) / 100;
  }

  private fibre(material = '', pct = ''): FormGroup {
    return this.fb.nonNullable.group({ material: [material, Validators.required], pct: [pct, [Validators.required, DECIMAL]] });
  }

  protected addFibre(): void {
    this.composition.push(this.fibre());
  }

  protected async submit(): Promise<void> {
    if (this.form.invalid || (this.composition.length > 0 && this.total() !== 100)) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const value = this.form.getRawValue();
    const body = blankToNull({ ...value, grammage_gsm: decimal(value.grammage_gsm), width_cm: decimal(value.width_cm), composition: value.composition.map((line) => ({ material: line['material'] as string, pct: decimal(line['pct'] as string) })) });
    const current = this.material();
    try {
      const saved = await firstValueFrom(current ? this.api.put<RawMaterial>(`production/materials/${current.ulid}`, body) : this.api.post<RawMaterial>('production/materials', body));
      this.toast.success('common.saved');
      this.ref.close(saved);
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }


}

/** An article, the client it is made for, and what one piece is expected to consume. */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, ButtonDirective, InputText, Textarea, ...FIELD, ...SELECT, ...DIALOG_PARTS, IconComponent, CheckboxComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data ? 'production.articles.edit' : 'production.articles.new') }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <div class="dw-form">
            <dw-field [label]="t('production.reference')"><input pInputText formControlName="reference" maxlength="60" required /></dw-field>
            <dw-field [label]="t('production.articles.family')"><input pInputText formControlName="family" maxlength="80" /></dw-field>
            <dw-field class="dw-span-2" [label]="t('production.designation')"><input pInputText formControlName="name" maxlength="191" required /></dw-field>
            <dw-field class="dw-span-2" [label]="t('production.clients.one')">
              <dw-select formControlName="client">
                <dw-option value="" label="—" />
                @for (client of clients(); track client.ulid) { <dw-option [value]="client.ulid" [label]="client.name" /> }
              </dw-select>
            </dw-field>

            <section class="dw-span-2 part">
              <h3 class="dw-h3">{{ t('production.articles.consumption') }}</h3>
              <p class="dw-muted">{{ t('production.articles.consumptionHint') }}</p>
              @for (line of materials.controls; track line; let i = $index) {
                <div class="line" [formGroup]="line">
                  <dw-select formControlName="material" [ariaLabel]="t('production.materials.one')" [placeholder]="t('production.materials.one')">
                    @for (material of options(); track material.ulid) { <dw-option [value]="material.ulid" [label]="material.reference + ' — ' + material.name + ' (' + material.unit + ')'" [disabled]="taken(material.ulid, i)" /> }
                  </dw-select>
                  <input pInputText formControlName="quantity_per_piece" inputmode="decimal" [placeholder]="t('production.articles.perPiece')" [attr.aria-label]="t('production.articles.perPiece')" />
                  <button pButton text rounded severity="secondary" class="dw-icon-btn" type="button" (click)="materials.removeAt(i)" [attr.aria-label]="t('common.remove')"><dw-icon>close</dw-icon></button>
                </div>
              }
              <button pButton text type="button" class="add" (click)="addMaterial()"><dw-icon>add</dw-icon>{{ t('production.articles.addMaterial') }}</button>
            </section>

            <dw-field class="dw-span-2" [label]="t('fields.notes')"><textarea pTextarea formControlName="notes" rows="2" maxlength="1000"></textarea></dw-field>
            @if (data) { <dw-checkbox class="dw-span-2" formControlName="is_active">{{ t('common.active') }}</dw-checkbox> }
          </div>
        </div>
        <div class="dw-dialog-foot">
          <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
          <button pButton type="submit" [disabled]="busy()">{{ t('common.save') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: `
    .dw-dialog-body { inline-size: min(640px, 86vw); }
    .part { display: grid; gap: var(--dw-2); } .part p { margin: 0; font-size: 13px; }
    .line { display: grid; grid-template-columns: minmax(0, 1fr) 150px auto; gap: var(--dw-2); align-items: center; }
    .add { justify-self: start; }
  `,
})
export class ArticleDialog {
  protected readonly data = dialogData<Article | null>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);
  private readonly fb = inject(FormBuilder);
  private readonly lists = inject(ProductionLists);

  protected readonly clients = toSignal(this.lists.clients(), { initialValue: [] });
  protected readonly options = toSignal(this.lists.materials(), { initialValue: [] });
  protected readonly busy = signal(false);
  protected readonly materials = this.fb.array((this.data?.materials ?? []).map((line) => this.line(line.material, line.quantity_per_piece)));
  protected readonly form = this.fb.nonNullable.group({
    reference: [this.data?.reference ?? '', Validators.required],
    name: [this.data?.name ?? '', Validators.required],
    family: [this.data?.family ?? ''],
    client: [this.data?.client?.ulid ?? ''],
    notes: [this.data?.notes ?? ''],
    is_active: [this.data?.is_active ?? true],
    materials: this.materials,
  });

  private line(material = '', quantity = ''): FormGroup {
    return this.fb.nonNullable.group({ material: [material, Validators.required], quantity_per_piece: [quantity, [Validators.required, DECIMAL]] });
  }

  protected addMaterial(): void {
    this.materials.push(this.line());
  }

  protected taken(ulid: string, index: number): boolean {
    return this.materials.controls.some((line, i) => i !== index && line.value.material === ulid);
  }

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const value = this.form.getRawValue();
    const body = blankToNull({ ...value, materials: value.materials.map((line) => ({ material: line['material'] as string, quantity_per_piece: decimal(line['quantity_per_piece'] as string) })) });
    try {
      this.ref.close(await firstValueFrom(this.data ? this.api.put<Article>(`production/articles/${this.data.ulid}`, body) : this.api.post<Article>('production/articles', body)));
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

/** Raw materials and articles: what is consumed, and what is made with it. */
@Component({
  imports: [
    HelpComponent,
    TranslocoDirective, PageHeaderComponent, ...TABLE, StatusComponent, SearchComponent, FilterBarComponent, CheckboxComponent, ButtonDirective, IconComponent, CodePipe, ...COLOR,
    Tabs, TabList, Tab, TabPanels, TabPanel,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('production.catalog.title')" [subtitle]="t('production.catalog.subtitle')" />
      <dw-help topic="productionCatalog" />
      <div class="dw-panel">
        <p-tabs [value]="0">
          <p-tablist><p-tab [value]="0">{{ t('production.materials.title') }}</p-tab><p-tab [value]="1">{{ t('production.articles.title') }}</p-tab></p-tablist>
          <p-tabpanels>
            <p-tabpanel [value]="0">
              <dw-filter-bar [active]="(materialSearch() ? 1 : 0) + (inactive() ? 1 : 0)" (cleared)="materialSearch.set(''); inactive.set(false); materials.reload()">
                <dw-search [placeholder]="t('common.search')" [query]="materialSearch()" (queryChange)="materialSearch.set($event); materials.reload()" />
                <dw-checkbox [checked]="inactive()" (checkedChange)="inactive.set($event); materials.reload(); articles.reload()">{{ t('production.showInactive') }}</dw-checkbox>
                <span class="dw-spacer"></span>
                @if (canManage) { <button pButton type="button" (click)="editMaterial(null)"><dw-icon>add</dw-icon>{{ t('production.materials.new') }}</button> }
              </dw-filter-bar>
              <dw-data-table [columns]="materialColumns" [rows]="materials.rows()" [loading]="materials.loading()" [error]="materials.error()" [total]="materials.total()" [pageIndex]="materials.page() - 1"
                [pageSize]="materials.perPage()" [clickable]="true" [emptyTitle]="t('production.materials.empty')" [emptyHint]="t('production.emptyHint')" emptyIcon="texture"
                (page)="materials.setPage($event.pageIndex + 1, $event.pageSize)" (rowClick)="openMaterial($event)" (retry)="materials.reload()">
                <ng-template dwCell="reference" let-m><span class="dw-mono">{{ m.reference }}</span></ng-template>
                <ng-template dwCell="composition" let-m>@for (line of m.composition; track line.material) { <span class="chip">{{ line.material | dwCode: 'material' }} {{ +line.pct }} %</span> } @empty { — }</ng-template>
                <ng-template dwCell="color" let-m>@if (m.color_family) { <dw-color [code]="m.color_family" /> }@if (m.color_family && m.color_label) { · }{{ m.color_label ?? (m.color_family ? '' : '—') }}</ng-template>
                <ng-template dwCell="batches" let-m>{{ m.batches.length || '—' }}</ng-template>
                <ng-template dwCell="status" let-m><dw-status kind="member" [value]="m.is_active ? 'active' : 'suspended'" [label]="t(m.is_active ? 'common.active' : 'common.inactive')" /></ng-template>
              </dw-data-table>
            </p-tabpanel>
            <p-tabpanel [value]="1">
              <dw-filter-bar [active]="articleSearch() ? 1 : 0" (cleared)="articleSearch.set(''); articles.reload()">
                <dw-search [placeholder]="t('common.search')" [query]="articleSearch()" (queryChange)="articleSearch.set($event); articles.reload()" />
                <span class="dw-spacer"></span>
                @if (canManage) { <button pButton type="button" (click)="editArticle(null)"><dw-icon>add</dw-icon>{{ t('production.articles.new') }}</button> }
              </dw-filter-bar>
              <dw-data-table [columns]="articleColumns" [rows]="articles.rows()" [loading]="articles.loading()" [error]="articles.error()" [total]="articles.total()" [pageIndex]="articles.page() - 1"
                [pageSize]="articles.perPage()" [clickable]="canManage" [emptyTitle]="t('production.articles.empty')" [emptyHint]="t('production.emptyHint')" emptyIcon="checkroom"
                (page)="articles.setPage($event.pageIndex + 1, $event.pageSize)" (rowClick)="editArticle($event)" (retry)="articles.reload()">
                <ng-template dwCell="reference" let-a><span class="dw-mono">{{ a.reference }}</span></ng-template>
                <ng-template dwCell="consumption" let-a>@for (line of a.materials; track line.material) { <div>{{ +line.quantity_per_piece }} {{ line.unit }} · <span class="dw-faint">{{ line.reference }}</span></div> } @empty { — }</ng-template>
                <ng-template dwCell="status" let-a><dw-status kind="member" [value]="a.is_active ? 'active' : 'suspended'" [label]="t(a.is_active ? 'common.active' : 'common.inactive')" /></ng-template>
              </dw-data-table>
            </p-tabpanel>
          </p-tabpanels>
        </p-tabs>
      </div>
    </ng-container>
  `,
  styles: '.chip { display: inline-block; margin-inline-end: 4px; padding: 1px 8px; border-radius: 999px; background: var(--dw-surface-2); font-size: 12px; }',
})
export class MaterialsPage {
  private readonly api = inject(Api);
  private readonly dialog = inject(Dialogs);
  private readonly router = inject(Router);

  protected readonly canManage = inject(Session).can('production.manage');
  protected readonly materialSearch = signal('');
  protected readonly articleSearch = signal('');
  protected readonly inactive = signal(false);
  protected readonly materials = new ListStore<RawMaterial>((query) => this.api.list<RawMaterial>('production/materials', { ...query, search: this.materialSearch(), with_inactive: this.inactive() }));
  protected readonly articles = new ListStore<Article>((query) => this.api.list<Article>('production/articles', { ...query, search: this.articleSearch(), with_inactive: this.inactive() }));
  protected readonly materialColumns: Column<RawMaterial>[] = [
    { key: 'reference', header: 'production.reference', width: '150px' },
    { key: 'name', header: 'production.designation', value: (m) => m.name },
    { key: 'supplier', header: 'production.suppliers.one', value: (m) => m.supplier?.name ?? '—', hideBelow: 900 },
    { key: 'unit', header: 'production.materials.unit', value: (m) => m.unit, width: '80px', hideBelow: 900 },
    { key: 'composition', header: 'lots.composition', hideBelow: 1100 },
    { key: 'color', header: 'lots.color', hideBelow: 1100 },
    { key: 'batches', header: 'production.materials.batches', align: 'end', hideBelow: 1100 },
    { key: 'status', header: 'fields.status' },
  ];
  protected readonly articleColumns: Column<Article>[] = [
    { key: 'reference', header: 'production.reference', width: '150px' },
    { key: 'name', header: 'production.designation', value: (a) => a.name },
    { key: 'family', header: 'production.articles.family', value: (a) => a.family ?? '—', hideBelow: 900 },
    { key: 'client', header: 'production.clients.one', value: (a) => a.client?.name ?? '—', hideBelow: 700 },
    { key: 'consumption', header: 'production.articles.consumption', hideBelow: 1100 },
    { key: 'status', header: 'fields.status' },
  ];

  /** The page of the material: its received lots, its consumption, its prices. */
  protected openMaterial(material: RawMaterial): void {
    void this.router.navigate(['/production/catalog', material.ulid]);
  }

  /** A new material opens on its own page, where its received lots are recorded. */
  protected editMaterial(material: RawMaterial | null): void {
    this.dialog.open<MaterialDialog, RawMaterial | null, RawMaterial | boolean>(MaterialDialog, { data: material }).afterClosed()
      .subscribe((saved) => (saved && typeof saved === 'object' ? this.openMaterial(saved) : saved && this.materials.reload()));
  }

  protected editArticle(article: Article | null): void {
    this.dialog.open<ArticleDialog, Article | null, Article>(ArticleDialog, { data: article }).afterClosed().subscribe((saved) => saved && this.articles.reload());
  }
}
