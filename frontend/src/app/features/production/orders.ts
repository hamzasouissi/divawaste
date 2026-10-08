import { ChangeDetectionStrategy, Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { Textarea } from 'primeng/textarea';

import { Api } from '../../core/api/api';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { DatePipe, LocalizedPipe, NumberPipe } from '../../core/format/pipes';
import { Column, TABLE } from '../../ui/data-table';
import { DateComponent } from '../../ui/date';
import { DIALOG_PARTS, DialogRef, Dialogs, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { FilterBarComponent } from '../../ui/filter-bar';
import { IconComponent } from '../../ui/icon';
import { ListStore } from '../../ui/list-store';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SearchComponent } from '../../ui/search';
import { SELECT } from '../../ui/select';
import { CustomerOrder, DECIMAL, ManufacturingOrder, OfStatus, OrderStatus, ProductionLists, blankToNull, decimal } from './production-data';
import { HelpComponent } from '../../ui/help';

const ORDER_STATUSES: OrderStatus[] = ['open', 'in_progress', 'completed', 'cancelled'];
const OF_STATUSES: OfStatus[] = ['planned', 'in_progress', 'completed', 'cancelled'];
const LINE_STYLES = `
  .part { display: grid; gap: var(--dw-2); } .part p { margin: 0; font-size: 13px; }
  .line { display: grid; gap: var(--dw-2); align-items: center; }
  .add { justify-self: start; }
`;
/** Colour of a status tag: done is green, running is amber, the rest is neutral. */
const tone = (status: string): string => (status === 'completed' ? 'approved' : status === 'in_progress' ? 'pending' : status === 'cancelled' ? 'rejected' : 'draft');

/** An order of a client: the articles it asks for. */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, ButtonDirective, InputText, Textarea, ...FIELD, ...SELECT, ...DIALOG_PARTS, IconComponent, DateComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data ? 'production.orders.edit' : 'production.orders.new') }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <div class="dw-form">
            <dw-field [label]="t('production.orders.number')"><input pInputText formControlName="order_number" maxlength="60" required /></dw-field>
            <dw-field [label]="t('production.clients.one')">
              <dw-select formControlName="client" required>
                @for (client of clients(); track client.ulid) { <dw-option [value]="client.ulid" [label]="client.name" /> }
              </dw-select>
            </dw-field>
            <dw-field [label]="t('production.orders.orderedOn')"><dw-date formControlName="ordered_on" /></dw-field>
            <dw-field [label]="t('production.orders.dueOn')"><dw-date formControlName="due_on" /></dw-field>
            <dw-field class="dw-span-2" [label]="t('fields.status')">
              <dw-select formControlName="status">@for (status of statuses; track status) { <dw-option [value]="status" [label]="t('production.orderStatus.' + status)" /> }</dw-select>
            </dw-field>
            <section class="dw-span-2 part">
              <h3 class="dw-h3">{{ t('production.orders.lines') }}</h3>
              @for (line of lines.controls; track line; let i = $index) {
                <div class="line" [formGroup]="line">
                  <dw-select formControlName="article" [ariaLabel]="t('production.articles.one')" [placeholder]="t('production.articles.one')">
                    @for (article of articles(); track article.ulid) { <dw-option [value]="article.ulid" [label]="article.reference + ' — ' + article.name" [disabled]="taken(article.ulid, i)" /> }
                  </dw-select>
                  <input pInputText type="number" formControlName="quantity" min="1" [placeholder]="t('fields.quantity')" [attr.aria-label]="t('fields.quantity')" />
                  <button pButton text rounded severity="secondary" class="dw-icon-btn" type="button" (click)="lines.removeAt(i)" [attr.aria-label]="t('common.remove')"><dw-icon>close</dw-icon></button>
                </div>
              }
              <button pButton text type="button" class="add" (click)="addLine()"><dw-icon>add</dw-icon>{{ t('production.orders.addLine') }}</button>
            </section>
            <dw-field class="dw-span-2" [label]="t('fields.notes')"><textarea pTextarea formControlName="notes" rows="2" maxlength="1000"></textarea></dw-field>
          </div>
        </div>
        <div class="dw-dialog-foot">
          <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
          <button pButton type="submit" [disabled]="busy()">{{ t('common.save') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: LINE_STYLES + '.dw-dialog-body { inline-size: min(640px, 86vw); } .line { grid-template-columns: minmax(0, 1fr) 140px auto; }',
})
export class OrderDialog {
  protected readonly data = dialogData<CustomerOrder | null>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);
  private readonly fb = inject(FormBuilder);
  private readonly lists = inject(ProductionLists);

  protected readonly statuses = ORDER_STATUSES;
  protected readonly clients = toSignal(this.lists.clients(), { initialValue: [] });
  protected readonly articles = toSignal(this.lists.articles(), { initialValue: [] });
  protected readonly busy = signal(false);
  protected readonly lines = this.fb.array((this.data?.lines ?? []).map((line) => this.line(line.article, line.quantity)));
  protected readonly form = this.fb.nonNullable.group({
    order_number: [this.data?.order_number ?? '', Validators.required],
    client: [this.data?.client?.ulid ?? '', Validators.required],
    ordered_on: [this.data?.ordered_on ?? ''],
    due_on: [this.data?.due_on ?? ''],
    status: [this.data?.status ?? ('open' as OrderStatus)],
    notes: [this.data?.notes ?? ''],
    lines: this.lines,
  });

  private line(article = '', quantity: number | null = null): FormGroup {
    return this.fb.nonNullable.group({ article: [article, Validators.required], quantity: [quantity, [Validators.required, Validators.min(1)]] });
  }

  protected addLine(): void {
    this.lines.push(this.line());
  }

  protected taken(ulid: string, index: number): boolean {
    return this.lines.controls.some((line, i) => i !== index && line.value.article === ulid);
  }

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const body = blankToNull(this.form.getRawValue());
    try {
      this.ref.close(await firstValueFrom(this.data ? this.api.put<CustomerOrder>(`production/orders/${this.data.ulid}`, body) : this.api.post<CustomerOrder>('production/orders', body)));
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

/** The orders of the clients. */
@Component({
  imports: [HelpComponent, TranslocoDirective, PageHeaderComponent, ...TABLE, StatusComponent, SearchComponent, FilterBarComponent, ButtonDirective, IconComponent, DatePipe, NumberPipe, ...SELECT],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('production.orders.title')" [subtitle]="t('production.orders.subtitle')">
        @if (canManage) { <button pButton type="button" (click)="edit(null)"><dw-icon>add</dw-icon>{{ t('production.orders.new') }}</button> }
      </dw-page-header>
      <dw-help topic="productionOrders" />
      <div class="dw-panel">
        <dw-filter-bar [active]="(search() ? 1 : 0) + (status() ? 1 : 0)" (cleared)="search.set(''); status.set(''); list.reload()">
          <dw-search [placeholder]="t('production.orders.number')" [query]="search()" (queryChange)="search.set($event); list.reload()" />
          <dw-select [placeholder]="t('fields.status')" [value]="status()" (valueChange)="status.set($event); list.reload()">
            <dw-option value="" [label]="t('common.all')" />
            @for (item of statuses; track item) { <dw-option [value]="item" [label]="t('production.orderStatus.' + item)" /> }
          </dw-select>
        </dw-filter-bar>
        <dw-data-table [columns]="columns" [rows]="list.rows()" [loading]="list.loading()" [error]="list.error()" [total]="list.total()" [pageIndex]="list.page() - 1" [pageSize]="list.perPage()"
          [clickable]="canManage" [emptyTitle]="t('production.orders.empty')" [emptyHint]="t('production.emptyHint')" emptyIcon="receipt_long"
          (page)="list.setPage($event.pageIndex + 1, $event.pageSize)" (rowClick)="edit($event)" (retry)="list.reload()">
          <ng-template dwCell="number" let-o><span class="dw-mono">{{ o.order_number }}</span></ng-template>
          <ng-template dwCell="articles" let-o>@for (line of o.lines; track line.article) { <div>{{ line.quantity | dwNumber }} × {{ line.reference }} <span class="dw-faint">{{ line.name }}</span></div> } @empty { — }</ng-template>
          <ng-template dwCell="dates" let-o>{{ o.ordered_on | dwDate }}<div class="dw-faint">{{ t('production.orders.dueOn') }} {{ o.due_on | dwDate }}</div></ng-template>
          <ng-template dwCell="status" let-o><dw-status kind="accreditation" [value]="tone(o.status)" [label]="t('production.orderStatus.' + o.status)" /></ng-template>
        </dw-data-table>
      </div>
    </ng-container>
  `,
})
export class OrdersPage {
  private readonly api = inject(Api);
  private readonly dialog = inject(Dialogs);

  protected readonly canManage = inject(Session).can('production.manage');
  protected readonly statuses = ORDER_STATUSES;
  protected readonly tone = tone;
  protected readonly search = signal('');
  protected readonly status = signal('');
  protected readonly list = new ListStore<CustomerOrder>((query) => this.api.list<CustomerOrder>('production/orders', { ...query, search: this.search(), status: this.status() }));
  protected readonly columns: Column<CustomerOrder>[] = [
    { key: 'number', header: 'production.orders.number', width: '160px' },
    { key: 'client', header: 'production.clients.one', value: (o) => o.client?.name ?? '—' },
    { key: 'articles', header: 'production.articles.title', hideBelow: 900 },
    { key: 'dates', header: 'production.orders.orderedOn', hideBelow: 700 },
    { key: 'ofs', header: 'production.of.short', align: 'end', value: (o) => o.manufacturing_orders || '—', hideBelow: 1100 },
    { key: 'status', header: 'fields.status' },
  ];

  protected edit(order: CustomerOrder | null): void {
    this.dialog.open<OrderDialog, CustomerOrder | null, CustomerOrder>(OrderDialog, { data: order }).afterClosed().subscribe((saved) => saved && this.list.reload());
  }
}

/**
 * A manufacturing order: the article and quantity, the stages it goes through — each on its own site, since an OF
 * can be cut in one place and sewn in another — and what it really consumed, next to what was expected.
 */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, LocalizedPipe, NumberPipe, ButtonDirective, InputText, Textarea, ...FIELD, ...SELECT, ...DIALOG_PARTS, IconComponent, DateComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data ? 'production.of.edit' : 'production.of.new') }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <div class="dw-form">
            <dw-field [label]="t('production.of.number')"><input pInputText formControlName="order_number" maxlength="60" required /></dw-field>
            <dw-field [label]="t('fields.status')">
              <dw-select formControlName="status">@for (status of statuses; track status) { <dw-option [value]="status" [label]="t('production.ofStatus.' + status)" /> }</dw-select>
            </dw-field>
            <dw-field [label]="t('production.orders.one')">
              <dw-select formControlName="customer_order">
                <dw-option value="" [label]="t('production.of.noOrder')" />
                @for (order of orders(); track order.ulid) { <dw-option [value]="order.ulid" [label]="order.order_number + ' — ' + (order.client?.name ?? '')" /> }
              </dw-select>
            </dw-field>
            <dw-field [label]="t('production.articles.one')">
              <dw-select formControlName="article" required>
                @for (article of offered(); track article.ulid) { <dw-option [value]="article.ulid" [label]="article.reference + ' — ' + article.name" /> }
              </dw-select>
            </dw-field>
            <dw-field [label]="t('production.of.planned')"><input pInputText type="number" formControlName="quantity_planned" min="1" required /></dw-field>
            <dw-field [label]="t('production.of.produced')"><input pInputText type="number" formControlName="quantity_produced" min="0" /></dw-field>
            <dw-field [label]="t('production.of.startedOn')"><dw-date formControlName="started_on" /></dw-field>
            <dw-field [label]="t('production.of.finishedOn')"><dw-date formControlName="finished_on" [min]="form.controls.started_on.value || null" /></dw-field>

            <section class="dw-span-2 part">
              <h3 class="dw-h3">{{ t('production.of.steps') }}</h3>
              <p class="dw-muted">{{ t('production.of.stepsHint') }}</p>
              @for (line of steps.controls; track line; let i = $index) {
                <div class="line step" [formGroup]="line">
                  <dw-select formControlName="step" [ariaLabel]="t('production.of.step')" [placeholder]="t('production.of.step')">
                    @for (step of stepOptions(); track step.code) { <dw-option [value]="step.code" [label]="step.name | dwLocalized" [disabled]="stepTaken(step.code, i)" /> }
                  </dw-select>
                  <dw-select formControlName="site" [ariaLabel]="t('fields.site')" [placeholder]="t('fields.site')">
                    @for (site of sites; track site.ulid) { <dw-option [value]="site.ulid" [label]="site.name" /> }
                  </dw-select>
                  <input pInputText type="number" formControlName="pieces_done" min="0" [placeholder]="t('production.of.piecesDone')" [attr.aria-label]="t('production.of.piecesDone')" />
                  <button pButton text rounded severity="secondary" class="dw-icon-btn" type="button" (click)="steps.removeAt(i)" [attr.aria-label]="t('common.remove')"><dw-icon>close</dw-icon></button>
                </div>
              }
              <button pButton text type="button" class="add" (click)="addStep()"><dw-icon>add</dw-icon>{{ t('production.of.addStep') }}</button>
            </section>

            <section class="dw-span-2 part">
              <h3 class="dw-h3">{{ t('production.of.consumption') }}</h3>
              @if (expected().length > 0) {
                <ul class="expected">
                  @for (item of expected(); track item.material) {
                    <li>{{ item.reference }} — {{ t('production.of.expected') }} <strong>{{ item.planned | dwNumber: 2 }} {{ item.unit }}</strong>@if (+item.consumed > 0) { , {{ t('production.of.consumed') }}
                      <strong [class.over]="+item.consumed > +item.planned">{{ item.consumed | dwNumber: 2 }} {{ item.unit }}</strong> }</li>
                  }
                </ul>
              }
              @for (line of consumptions.controls; track line; let i = $index) {
                <div class="line use" [formGroup]="line">
                  <dw-select formControlName="material" [ariaLabel]="t('production.materials.one')" [placeholder]="t('production.materials.one')">
                    @for (material of materials(); track material.ulid) { <dw-option [value]="material.ulid" [label]="material.reference + ' — ' + material.name + ' (' + material.unit + ')'" /> }
                  </dw-select>
                  <dw-select formControlName="batch" [ariaLabel]="t('production.materials.batchNumber')" [placeholder]="t('production.materials.batchNumber')">
                    <dw-option value="" [label]="t('production.of.anyBatch')" />
                    @for (batch of batchesOf(line.value.material); track batch.ulid) { <dw-option [value]="batch.ulid" [label]="batch.batch_number" /> }
                  </dw-select>
                  <input pInputText formControlName="quantity" inputmode="decimal" [placeholder]="t('fields.quantity')" [attr.aria-label]="t('fields.quantity')" />
                  <button pButton text rounded severity="secondary" class="dw-icon-btn" type="button" (click)="consumptions.removeAt(i)" [attr.aria-label]="t('common.remove')"><dw-icon>close</dw-icon></button>
                </div>
              }
              <button pButton text type="button" class="add" (click)="addConsumption()"><dw-icon>add</dw-icon>{{ t('production.of.addConsumption') }}</button>
            </section>
            <dw-field class="dw-span-2" [label]="t('fields.notes')"><textarea pTextarea formControlName="notes" rows="2" maxlength="1000"></textarea></dw-field>
          </div>
        </div>
        <div class="dw-dialog-foot">
          <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
          <button pButton type="submit" [disabled]="busy()">{{ t('common.save') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: LINE_STYLES + `
    .dw-dialog-body { inline-size: min(760px, 90vw); }
    .line.step { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) 150px auto; }
    .line.use { grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr) 120px auto; }
    .expected { margin: 0; padding-inline-start: 18px; font-size: 13px; color: var(--dw-ink-2); } .over { color: var(--dw-danger); }
    @media (max-width: 640px) { .line.step, .line.use { grid-template-columns: minmax(0, 1fr) auto; } }
  `,
})
export class ManufacturingOrderDialog {
  protected readonly data = dialogData<ManufacturingOrder | null>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);
  private readonly fb = inject(FormBuilder);
  private readonly lists = inject(ProductionLists);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly statuses = OF_STATUSES;
  protected readonly sites = inject(Session).sitesFor('sites.view');
  protected readonly orders = toSignal(this.lists.orders(), { initialValue: [] });
  private readonly articles = toSignal(this.lists.articles(), { initialValue: [] });
  protected readonly materials = toSignal(this.lists.materials(), { initialValue: [] });
  protected readonly stepOptions = toSignal(this.lists.steps(), { initialValue: [] });
  protected readonly busy = signal(false);
  protected readonly expected = signal(this.data?.planned_consumption ?? []);
  protected readonly steps = this.fb.array((this.data?.steps ?? []).map((line) => this.step(line.step, line.site, line.pieces_done)));
  protected readonly consumptions = this.fb.array((this.data?.consumptions ?? []).map((line) => this.consumption(line.material, line.batch ?? '', line.quantity)));
  protected readonly form = this.fb.nonNullable.group({
    order_number: [this.data?.order_number ?? '', Validators.required],
    status: [this.data?.status ?? ('planned' as OfStatus)],
    customer_order: [this.data?.customer_order?.ulid ?? ''],
    article: [this.data?.article.ulid ?? '', Validators.required],
    quantity_planned: [this.data?.quantity_planned ?? (null as number | null), [Validators.required, Validators.min(1)]],
    quantity_produced: [this.data?.quantity_produced ?? (null as number | null), Validators.min(0)],
    started_on: [this.data?.started_on ?? ''],
    finished_on: [this.data?.finished_on ?? ''],
    notes: [this.data?.notes ?? ''],
    steps: this.steps,
    consumptions: this.consumptions,
  });
  private readonly chosenOrder = toSignal(this.form.controls.customer_order.valueChanges, { initialValue: this.form.controls.customer_order.value });
  /** For an order, only the articles of that order; otherwise any article. */
  protected readonly offered = computed(() => {
    const order = this.orders().find((item) => item.ulid === this.chosenOrder());

    return order ? this.articles().filter((article) => order.lines.some((line) => line.article === article.ulid)) : this.articles();
  });

  private step(step = '', site = '', pieces: number | null = null): FormGroup {
    return this.fb.nonNullable.group({ step: [step, Validators.required], site: [site || (this.sites[0]?.ulid ?? ''), Validators.required], pieces_done: [pieces, Validators.min(0)] });
  }

  private consumption(material = '', batch = '', quantity = ''): FormGroup {
    const line = this.fb.nonNullable.group({ material: [material, Validators.required], batch: [batch], quantity: [quantity, [Validators.required, DECIMAL]] });
    // A batch belongs to one material: choosing another material forgets it. (Changes made by the user only: the
    // control does not announce the value it starts with.)
    line.controls.material.valueChanges.pipe(takeUntilDestroyed(this.destroyRef)).subscribe(() => line.controls.batch.setValue(''));

    return line;
  }

  protected addStep(): void {
    this.steps.push(this.step());
  }

  protected addConsumption(): void {
    this.consumptions.push(this.consumption());
  }

  protected stepTaken(code: string, index: number): boolean {
    return this.steps.controls.some((line, i) => i !== index && line.value.step === code);
  }

  protected batchesOf(material: string): { ulid: string; batch_number: string }[] {
    return this.materials().find((item) => item.ulid === material)?.batches ?? [];
  }

  constructor() {
    // The article must be one of the chosen order: when the user changes the order, a choice that no longer fits is cleared.
    this.form.controls.customer_order.valueChanges.pipe(takeUntilDestroyed(this.destroyRef)).subscribe((order) => {
      const lines = this.orders().find((item) => item.ulid === order)?.lines;
      if (lines && !lines.some((line) => line.article === this.form.controls.article.value)) {
        this.form.controls.article.setValue('');
      }
    });
  }

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const value = this.form.getRawValue();
    const body = blankToNull({
      ...value,
      steps: value.steps.map((line) => blankToNull(line)),
      consumptions: value.consumptions.map((line) => ({ material: line['material'] as string, batch: (line['batch'] as string) || null, quantity: decimal(line['quantity'] as string) })),
    });
    try {
      this.ref.close(await firstValueFrom(this.data ? this.api.put<ManufacturingOrder>(`production/manufacturing-orders/${this.data.ulid}`, body) : this.api.post<ManufacturingOrder>('production/manufacturing-orders', body)));
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

/** The manufacturing orders (OF), with where each one stands. */
@Component({
  imports: [HelpComponent, TranslocoDirective, PageHeaderComponent, ...TABLE, StatusComponent, SearchComponent, FilterBarComponent, ButtonDirective, IconComponent, DatePipe, NumberPipe, LocalizedPipe, ...SELECT],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('production.of.title')" [subtitle]="t('production.of.subtitle')">
        @if (canManage) { <button pButton type="button" (click)="edit(null)"><dw-icon>add</dw-icon>{{ t('production.of.new') }}</button> }
      </dw-page-header>
      <dw-help topic="manufacturingOrders" />
      <div class="dw-panel">
        <dw-filter-bar [active]="(search() ? 1 : 0) + (status() ? 1 : 0) + (site() ? 1 : 0)" (cleared)="search.set(''); status.set(''); site.set(''); list.reload()">
          <dw-search [placeholder]="t('production.of.number')" [query]="search()" (queryChange)="search.set($event); list.reload()" />
          <dw-select [placeholder]="t('fields.status')" [value]="status()" (valueChange)="status.set($event); list.reload()">
            <dw-option value="" [label]="t('common.all')" />
            @for (item of statuses; track item) { <dw-option [value]="item" [label]="t('production.ofStatus.' + item)" /> }
          </dw-select>
          @if (sites.length > 1) {
            <dw-select [placeholder]="t('fields.site')" [value]="site()" (valueChange)="site.set($event); list.reload()">
              <dw-option value="" [label]="t('common.allSites')" />
              @for (item of sites; track item.ulid) { <dw-option [value]="item.ulid" [label]="item.name" /> }
            </dw-select>
          }
        </dw-filter-bar>
        <dw-data-table [columns]="columns" [rows]="list.rows()" [loading]="list.loading()" [error]="list.error()" [total]="list.total()" [pageIndex]="list.page() - 1" [pageSize]="list.perPage()"
          [clickable]="true" [emptyTitle]="t('production.of.empty')" [emptyHint]="t('production.emptyHint')" emptyIcon="precision_manufacturing"
          (page)="list.setPage($event.pageIndex + 1, $event.pageSize)" (rowClick)="edit($event)" (retry)="list.reload()">
          <ng-template dwCell="number" let-of><span class="dw-mono">{{ of.order_number }}</span>@if (of.customer_order) { <div class="dw-faint dw-mono">{{ of.customer_order.order_number }}</div> }</ng-template>
          <ng-template dwCell="article" let-of>{{ of.article.reference }}<div class="dw-faint">{{ of.article.name }}</div></ng-template>
          <ng-template dwCell="quantity" let-of>{{ of.quantity_produced ?? '—' | dwNumber }} / {{ of.quantity_planned | dwNumber }}</ng-template>
          <ng-template dwCell="steps" let-of>@for (step of of.steps; track step.step) { <div>{{ step.name | dwLocalized }} <span class="dw-faint">· {{ step.site_name }}</span></div> } @empty { — }</ng-template>
          <ng-template dwCell="dates" let-of>{{ of.started_on | dwDate }}@if (of.finished_on) { <div class="dw-faint">→ {{ of.finished_on | dwDate }}</div> }</ng-template>
          <ng-template dwCell="status" let-of><dw-status kind="accreditation" [value]="tone(of.status)" [label]="t('production.ofStatus.' + of.status)" /></ng-template>
        </dw-data-table>
      </div>
    </ng-container>
  `,
})
export class ManufacturingOrdersPage {
  private readonly api = inject(Api);
  private readonly dialog = inject(Dialogs);
  private readonly toast = inject(Toast);
  private readonly session = inject(Session);

  protected readonly canManage = this.session.can('production.manage');
  protected readonly statuses = OF_STATUSES;
  protected readonly sites = this.session.sitesFor('sites.view');
  protected readonly tone = tone;
  protected readonly search = signal('');
  protected readonly status = signal('');
  protected readonly site = signal('');
  protected readonly list = new ListStore<ManufacturingOrder>((query) => this.api.list<ManufacturingOrder>('production/manufacturing-orders', { ...query, search: this.search(), status: this.status(), site: this.site() }));
  protected readonly columns: Column<ManufacturingOrder>[] = [
    { key: 'number', header: 'production.of.number', width: '170px' },
    { key: 'article', header: 'production.articles.one' },
    { key: 'client', header: 'production.clients.one', value: (of) => of.client?.name ?? '—', hideBelow: 900 },
    { key: 'quantity', header: 'production.of.producedPlanned', align: 'end', hideBelow: 700 },
    { key: 'steps', header: 'production.of.steps', hideBelow: 1100 },
    { key: 'dates', header: 'production.of.startedOn', hideBelow: 1100 },
    { key: 'status', header: 'fields.status' },
  ];

  /** The list is light; the form needs the stages and the consumption, read on opening. */
  protected async edit(row: ManufacturingOrder | null): Promise<void> {
    if (!this.canManage) {
      return;
    }
    try {
      const data = row ? await firstValueFrom(this.api.get<ManufacturingOrder>(`production/manufacturing-orders/${row.ulid}`)) : null;
      this.dialog.open<ManufacturingOrderDialog, ManufacturingOrder | null, ManufacturingOrder>(ManufacturingOrderDialog, { data }).afterClosed().subscribe((saved) => saved && this.list.reload());
    } catch (error) {
      this.toast.error(error);
    }
  }
}
