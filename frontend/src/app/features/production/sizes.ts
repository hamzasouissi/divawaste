import { CdkDrag, CdkDragDrop, CdkDropList, moveItemInArray } from '@angular/cdk/drag-drop';
import { ChangeDetectionStrategy, Component, computed, inject, input, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';

import { Api } from '../../core/api/api';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { NumberPipe } from '../../core/format/pipes';
import { CheckboxComponent } from '../../ui/checkbox';
import { DIALOG_PARTS, DialogRef, Dialogs, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { ManufacturingOrder, ProductionLists, Size, SizeGrid } from './production-data';

/** "XS, S M;L" → ["XS", "S", "M", "L"]: sizes typed in one line, in order, each once. */
export const parseSizes = (text: string): string[] => [...new Set(text.split(/[\s,;]+/).map((size) => size.trim()).filter(Boolean))];

/** A size run, read only: a grid's sizes side by side like a size chart (ruler), or an OF's sizes with their pieces and the total. */
@Component({
  selector: 'dw-size-run',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [NumberPipe],
  template: `
    <div class="run" [class.ruler]="!quantities()" role="table">
      @for (size of sizes(); track size) {
        <div class="cell" role="cell"><span class="size">{{ size }}</span>@if (quantities(); as q) { <span class="qty" [class.zero]="!q[size]">{{ (q[size] ?? 0) | dwNumber }}</span> }</div>
      }
      @if (quantities()) { <div class="cell total" role="cell"><span class="size">{{ totalLabel() }}</span><span class="qty">{{ sum() | dwNumber }}</span></div> }
    </div>
  `,
  styles: `
    :host { display: block; min-inline-size: 0; }
    .run { display: flex; inline-size: fit-content; max-inline-size: 100%; overflow-x: auto; border: 1px solid var(--dw-line-strong); border-radius: var(--dw-radius); background: var(--dw-surface); }
    .cell { display: grid; grid-template-rows: auto auto; min-inline-size: 52px; border-inline-start: 1px solid var(--dw-line); }
    .cell:first-child { border-inline-start: 0; }
    .size { padding: 4px 10px; background: var(--dw-surface-2); font-size: 11.5px; font-weight: 700; letter-spacing: 0.02em; color: var(--dw-ink-2); text-align: center; white-space: nowrap; }
    .qty { padding: 5px 10px; font-size: 14.5px; font-weight: 650; text-align: center; font-variant-numeric: tabular-nums; color: var(--dw-ink); }
    .qty.zero { color: var(--dw-ink-3); font-weight: 500; }
    .total .size { background: var(--dw-primary-soft); color: var(--dw-primary-strong); } .total .qty { color: var(--dw-primary-strong); }
    .ruler .cell { min-inline-size: 44px; } .ruler .size { padding: 7px 10px; background: var(--dw-surface); font-size: 13px; color: var(--dw-ink); }
  `,
})
export class SizeRunComponent {
  readonly sizes = input.required<string[]>();
  readonly quantities = input<Record<string, number> | null>(null);
  readonly totalLabel = input('Total');
  protected readonly sum = computed(() => this.sizes().reduce((total, size) => total + (this.quantities()?.[size] || 0), 0));
}

/**
 * A grid: a name and some of the company's sizes, clicked; a size the company does not have yet is typed and added at
 * once. The grid keeps the company's order; its size run shows as it is built.
 */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, ButtonDirective, InputText, ...FIELD, ...DIALOG_PARTS, CheckboxComponent, IconComponent, SizeRunComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data.grid ? 'production.sizes.edit' : 'production.sizes.new') }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <div class="dw-form">
            <dw-field class="dw-span-2" [label]="t('production.sizes.name')"><input pInputText formControlName="name" maxlength="80" required [placeholder]="t('production.sizes.namePlaceholder')" /></dw-field>
            <div class="dw-span-2 part">
              <div class="label">{{ t('production.sizes.gridSizes') }} <span class="dw-faint">· {{ t('production.sizes.picked', { count: picked().length }) }}</span>
                @if (sizes().length > 1) { <button type="button" class="link" (click)="all()">{{ t(picked().length === sizes().length ? 'production.sizes.none' : 'production.sizes.all') }}</button> }</div>
              <div class="chips">
                @for (s of sizes(); track s.ulid) {
                  <button type="button" class="pick" [class.on]="picked().includes(s.code)" [attr.aria-pressed]="picked().includes(s.code)" (click)="toggle(s.code)">{{ s.code }}</button>
                }
                <span class="new">
                  <input [value]="draft()" (input)="draft.set($any($event.target).value)" (keydown.enter)="$event.preventDefault(); create()" [placeholder]="t('production.sizes.newSizes')" [attr.aria-label]="t('production.sizes.newSizes')" />
                  <button type="button" (click)="create()" [disabled]="!draft().trim()" [attr.aria-label]="t('production.sizes.add')" [title]="t('production.sizes.add')"><dw-icon>add</dw-icon></button>
                </span>
              </div>
              @if (tried() && picked().length === 0) { <small class="dw-field-error" role="alert">{{ t('production.sizes.pickOne') }}</small> }
            </div>
            @if (ordered().length > 0) {
              <div class="dw-span-2 part">
                <div class="label">{{ t('production.sizes.preview') }}</div>
                <dw-size-run [sizes]="ordered()" />
              </div>
            }
            @if (data.grid) { <dw-checkbox class="dw-span-2" formControlName="is_active">{{ t('common.active') }}</dw-checkbox> }
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
    .dw-dialog-body { inline-size: min(560px, 88vw); }
    .part { display: grid; gap: var(--dw-2); }
    .label { display: flex; align-items: baseline; gap: 6px; font-size: 13px; font-weight: 600; color: var(--dw-ink-2); }
    .link { all: unset; cursor: pointer; margin-inline-start: auto; font-size: 12.5px; font-weight: 600; color: var(--dw-primary); } .link:hover { text-decoration: underline; }
    .chips { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
    .pick { all: unset; box-sizing: border-box; cursor: pointer; min-inline-size: 44px; padding: 7px 12px; border-radius: var(--dw-radius); text-align: center; font-size: 13px; font-weight: 650;
      background: var(--dw-surface); color: var(--dw-ink-2); box-shadow: inset 0 0 0 1px var(--dw-line-strong); transition: background 100ms, color 100ms, box-shadow 100ms; }
    .pick:hover { box-shadow: inset 0 0 0 1px var(--dw-primary); color: var(--dw-ink); }
    .pick.on { background: var(--dw-primary); color: var(--dw-on-primary); box-shadow: none; }
    .pick:focus-visible { outline: 2px solid var(--dw-focus); outline-offset: 2px; }
    .new { display: inline-flex; align-items: center; border: 1px dashed var(--dw-line-strong); border-radius: var(--dw-radius); }
    .new:focus-within { border-color: var(--dw-focus); }
    .new input { inline-size: 130px; padding: 6px 4px 6px 10px; border: 0; background: transparent; font: inherit; font-size: 13px; color: var(--dw-ink); outline: none; }
    .new button { all: unset; cursor: pointer; display: inline-grid; place-items: center; inline-size: 28px; block-size: 28px; color: var(--dw-primary); } .new button:disabled { color: var(--dw-ink-3); cursor: default; }
    .new dw-icon { font-size: 18px; }
  `,
})
export class SizeGridDialog {
  protected readonly data = dialogData<{ grid: SizeGrid | null; sizes: Size[] }>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);
  protected readonly busy = signal(false);
  protected readonly tried = signal(false);
  protected readonly draft = signal('');
  protected readonly sizes = signal<Size[]>(this.data.sizes);
  protected readonly picked = signal<string[]>(this.data.grid?.sizes ?? []);
  /** The grid as it will be: the sizes clicked, in the company's order. */
  protected readonly ordered = computed(() => this.sizes().map((s) => s.code).filter((code) => this.picked().includes(code)));
  protected readonly form = inject(FormBuilder).nonNullable.group({
    name: [this.data.grid?.name ?? '', Validators.required],
    is_active: [this.data.grid?.is_active ?? true],
  });

  protected toggle(code: string): void {
    this.picked.update((list) => (list.includes(code) ? list.filter((item) => item !== code) : [...list, code]));
  }

  protected all(): void {
    this.picked.set(this.picked().length === this.sizes().length ? [] : this.sizes().map((s) => s.code));
  }

  /** Sizes the company does not have yet: added to its list, and to this grid. */
  protected async create(): Promise<void> {
    const codes = parseSizes(this.draft());
    if (codes.length === 0) {
      return;
    }
    try {
      this.sizes.set(await firstValueFrom(this.api.post<Size[]>('production/sizes', { codes })));
      const known = new Map(this.sizes().map((s) => [s.code.toLowerCase(), s.code]));
      this.picked.update((list) => [...new Set([...list, ...codes.map((code) => known.get(code.toLowerCase()) ?? code)])]);
      this.draft.set('');
    } catch (error) {
      this.toast.error(error);
    }
  }

  protected async submit(): Promise<void> {
    this.tried.set(true);
    if (this.form.invalid || this.picked().length === 0) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const body = { name: this.form.controls.name.value.trim(), sizes: this.ordered(), is_active: this.form.controls.is_active.value };
    try {
      const grid = this.data.grid;
      this.ref.close(await firstValueFrom(grid ? this.api.put<SizeGrid>(`production/size-grids/${grid.ulid}`, body) : this.api.post<SizeGrid>('production/size-grids', body)));
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

/**
 * The company's grids, each shown as its size run, and below them the company's sizes: added several at once,
 * dragged into order. A size a grid or an OF uses cannot be removed.
 */
@Component({
  selector: 'dw-size-grids',
  imports: [TranslocoDirective, ButtonDirective, IconComponent, CdkDropList, CdkDrag, SizeRunComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <div class="page">
        <section>
          <header class="head">
            <div>
              <h3 class="dw-h2">{{ t('production.sizes.title') }}</h3>
              <p class="dw-muted">{{ t('production.sizes.intro') }}</p>
            </div>
            @if (canManage && grids().length > 0) { <button pButton type="button" (click)="edit(null)"><dw-icon>add</dw-icon>{{ t('production.sizes.new') }}</button> }
          </header>
          <div class="grids">
            @for (grid of grids(); track grid.ulid) {
              <article class="grid" [class.off]="!grid.is_active">
                <div class="grid-head">
                  <strong>{{ grid.name }}</strong>
                  <span class="badge">{{ grid.is_active ? t('production.sizes.ofCount', { count: grid.orders }) : t('common.inactive') }}</span>
                  <span class="dw-spacer"></span>
                  @if (canManage) { <button pButton text rounded size="small" severity="secondary" class="dw-icon-btn" type="button" (click)="edit(grid)" [attr.aria-label]="t('common.edit')" [title]="t('common.edit')"><dw-icon>edit</dw-icon></button> }
                </div>
                <dw-size-run [sizes]="grid.sizes" />
              </article>
            } @empty {
              <div class="empty">
                <dw-icon>straighten</dw-icon>
                <p>{{ t('production.sizes.empty') }}</p>
                @if (canManage) { <button pButton type="button" (click)="edit(null)"><dw-icon>add</dw-icon>{{ t('production.sizes.new') }}</button> }
              </div>
            }
          </div>
        </section>

        <section class="library">
          <header class="head">
            <div>
              <h3 class="dw-h3">{{ t('production.sizes.catalogueTitle') }} <span class="count">{{ sizes().length }}</span></h3>
              <p class="dw-muted">{{ t('production.sizes.catalogueIntro') }}</p>
            </div>
          </header>
          <div class="list" cdkDropList cdkDropListOrientation="mixed" [cdkDropListDisabled]="!canManage" (cdkDropListDropped)="drop($event)">
            @for (s of sizes(); track s.ulid; let i = $index, first = $first, last = $last) {
              <span class="size" cdkDrag [class.movable]="canManage" [attr.tabindex]="canManage ? 0 : null" [title]="canManage ? t('production.sizes.dragHint') : ''"
                (keydown.arrowleft)="!first && move(i, -1)" (keydown.arrowright)="!last && move(i, 1)">
                @if (canManage) { <dw-icon class="grip">drag_indicator</dw-icon> }
                <b>{{ s.code }}</b>
                @if (s.grids + s.orders > 0) { <span class="used" [title]="t('production.sizes.usedBy', { grids: s.grids, orders: s.orders })"></span> }
                @else if (canManage) {
                  <button type="button" class="x" (click)="remove(s)" [attr.aria-label]="t('production.sizes.remove', { size: s.code })" [title]="t('production.sizes.remove', { size: s.code })"><dw-icon>close</dw-icon></button>
                }
              </span>
            }
            @if (canManage) {
              <form class="add" (submit)="$event.preventDefault(); add()">
                <input [value]="draft()" (input)="draft.set($any($event.target).value)" [placeholder]="t('production.sizes.addPlaceholder')" [attr.aria-label]="t('production.sizes.addPlaceholder')" />
                <button type="submit" [disabled]="parsed().length === 0 || busy()" [attr.aria-label]="t('production.sizes.add')" [title]="t('production.sizes.add')"><dw-icon>add</dw-icon></button>
              </form>
            }
          </div>
          @if (canManage) { <p class="dw-faint hint">{{ t('production.sizes.libraryHint') }}</p> }
        </section>
      </div>
    </ng-container>
  `,
  styles: `
    .page { display: grid; gap: var(--dw-5); padding: var(--dw-5) var(--dw-5) var(--dw-4); }
    @media (max-width: 640px) { .page { padding: var(--dw-4) var(--dw-3); } }
    .head { display: flex; justify-content: space-between; align-items: flex-start; gap: var(--dw-3); margin-block-end: var(--dw-3); }
    .head h3 { margin: 0; } .head p { margin: 2px 0 0; font-size: 13px; }
    .count { display: inline-grid; place-items: center; min-inline-size: 22px; block-size: 20px; margin-inline-start: 4px; padding: 0 6px; border-radius: 999px; background: var(--dw-surface-3); font-size: 11.5px; color: var(--dw-ink-2); vertical-align: 1px; }
    .grids { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: var(--dw-3); }
    .grid { display: grid; gap: 10px; min-inline-size: 0; padding: 12px 12px 16px 16px; border-radius: var(--dw-radius-lg); background: var(--dw-surface); box-shadow: var(--dw-shadow); transition: box-shadow 140ms; }
    .grid:hover { box-shadow: var(--dw-shadow-hover); }
    .grid.off { opacity: 0.6; }
    .grid-head { display: flex; align-items: center; gap: var(--dw-2); min-block-size: 32px; } .grid-head strong { font-size: 15px; }
    .badge { padding: 1px 8px; border-radius: 999px; background: var(--dw-surface-2); color: var(--dw-ink-3); font-size: 12px; font-weight: 560; white-space: nowrap; }
    .empty { grid-column: 1 / -1; display: grid; justify-items: center; gap: var(--dw-2); padding: var(--dw-5); border: 1px dashed var(--dw-line-strong); border-radius: var(--dw-radius-lg); text-align: center; color: var(--dw-ink-3); }
    .empty dw-icon { font-size: 28px; } .empty p { margin: 0; max-inline-size: 440px; font-size: 13px; }
    .library { padding: var(--dw-4); border-radius: var(--dw-radius-lg); background: var(--dw-surface-2); }
    .list { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .size { display: inline-flex; align-items: center; gap: 2px; block-size: 34px; padding: 0 6px 0 4px; border-radius: var(--dw-radius); background: var(--dw-surface); box-shadow: var(--dw-shadow); font-size: 13.5px; }
    .size b { padding: 0 6px 0 2px; font-weight: 650; }
    .size.movable { cursor: grab; } .size:focus-visible { outline: 2px solid var(--dw-focus); outline-offset: 2px; }
    .grip { font-size: 16px; color: var(--dw-ink-3); opacity: 0.5; }
    .used { inline-size: 6px; block-size: 6px; margin: 0 6px 0 0; border-radius: 50%; background: var(--dw-primary); opacity: 0.55; }
    .x { all: unset; cursor: pointer; display: inline-grid; place-items: center; inline-size: 22px; block-size: 22px; border-radius: 50%; color: var(--dw-ink-3); }
    .x dw-icon { font-size: 15px; } .x:hover { background: var(--dw-danger-soft); color: var(--dw-danger); } .x:focus-visible { outline: 2px solid var(--dw-focus); }
    @media (hover: hover) { .size .x { opacity: 0; } .size:hover .x, .size:focus-within .x { opacity: 1; } }
    .size.cdk-drag-preview { box-shadow: var(--dw-shadow-pop); cursor: grabbing; }
    .size.cdk-drag-placeholder { opacity: 0.3; }
    .list.cdk-drop-list-dragging .size:not(.cdk-drag-placeholder) { transition: transform 180ms var(--dw-ease); }
    .add { display: inline-flex; align-items: center; block-size: 34px; border: 1px dashed var(--dw-line-strong); border-radius: var(--dw-radius); background: var(--dw-surface); }
    .add:focus-within { border-color: var(--dw-focus); box-shadow: 0 0 0 3px var(--dw-focus-soft); }
    .add input { inline-size: 150px; padding: 0 4px 0 10px; border: 0; background: transparent; font: inherit; font-size: 13px; color: var(--dw-ink); outline: none; }
    .add button { all: unset; cursor: pointer; display: inline-grid; place-items: center; inline-size: 30px; block-size: 30px; color: var(--dw-primary); } .add button:disabled { color: var(--dw-ink-3); cursor: default; }
    .hint { margin: var(--dw-3) 0 0; font-size: 12px; }
  `,
})
export class SizeGridsComponent {
  private readonly api = inject(Api);
  private readonly dialog = inject(Dialogs);
  private readonly toast = inject(Toast);
  // The company's sizes are its own, never an ERP's: managed here even with an ERP.
  protected readonly canManage = inject(Session).can('production.manage');
  protected readonly grids = signal<SizeGrid[]>([]);
  protected readonly sizes = signal<Size[]>([]);
  protected readonly draft = signal('');
  protected readonly busy = signal(false);
  protected readonly parsed = computed(() => parseSizes(this.draft()));

  constructor() {
    this.load();
  }

  private load(): void {
    this.api.get<SizeGrid[]>('production/size-grids', { with_inactive: true }).subscribe((grids) => this.grids.set(grids));
    this.api.get<Size[]>('production/sizes').subscribe((sizes) => this.sizes.set(sizes));
  }

  protected async add(): Promise<void> {
    if (this.parsed().length === 0) {
      return;
    }
    this.busy.set(true);
    try {
      this.sizes.set(await firstValueFrom(this.api.post<Size[]>('production/sizes', { codes: this.parsed() })));
      this.draft.set('');
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }

  protected drop(event: CdkDragDrop<unknown>): void {
    if (event.previousIndex !== event.currentIndex) {
      const list = [...this.sizes()];
      moveItemInArray(list, event.previousIndex, event.currentIndex);
      void this.order(list);
    }
  }

  /** With the keyboard: the arrows move a focused size. */
  protected move(index: number, step: -1 | 1): void {
    const list = [...this.sizes()];
    [list[index], list[index + step]] = [list[index + step], list[index]];
    void this.order(list);
  }

  private async order(list: Size[]): Promise<void> {
    this.sizes.set(list);
    try {
      this.sizes.set(await firstValueFrom(this.api.put<Size[]>('production/sizes/order', { sizes: list.map((s) => s.ulid) })));
      // The grids follow the company's order.
      this.api.get<SizeGrid[]>('production/size-grids', { with_inactive: true }).subscribe((grids) => this.grids.set(grids));
    } catch (error) {
      this.toast.error(error);
      this.load();
    }
  }

  /** Removed at once; "Annuler" adds it back (at the end of the list). */
  protected async remove(size: Size): Promise<void> {
    try {
      await firstValueFrom(this.api.delete(`production/sizes/${size.ulid}`));
      this.sizes.update((list) => list.filter((s) => s.ulid !== size.ulid));
      this.toast.undoable('production.sizes.removed', () => void firstValueFrom(this.api.post<Size[]>('production/sizes', { codes: [size.code] })).then((list) => this.sizes.set(list)), { size: size.code });
    } catch (error) {
      this.toast.error(error);
    }
  }

  protected edit(grid: SizeGrid | null): void {
    this.dialog.open<SizeGridDialog, { grid: SizeGrid | null; sizes: Size[] }, SizeGrid>(SizeGridDialog, { data: { grid, sizes: this.sizes() } }).afterClosed()
      .subscribe(() => this.load());
  }
}

/**
 * The sizes of an OF, from its page: a grid (all its sizes), or sizes picked one by one; then the pieces of each,
 * typed in its size run. Their total becomes the quantity planned.
 */
@Component({
  imports: [TranslocoDirective, ButtonDirective, ...DIALOG_PARTS, NumberPipe, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t('production.sizes.ofTitle', { of: data.order_number }) }}</dw-dialog-title>
      <div class="dw-dialog-body">
        @if (catalogue().length === 0 && grids().length === 0) {
          <div class="empty"><dw-icon>straighten</dw-icon><p>{{ t('production.sizes.sizesFirst') }}</p></div>
        } @else {
          <div class="part">
            <div class="label">{{ t('production.sizes.from') }}</div>
            <div class="chips" role="radiogroup" [attr.aria-label]="t('production.sizes.from')">
              @for (g of grids(); track g.ulid) {
                <button type="button" class="pick" role="radio" [class.on]="grid() === g.ulid" [attr.aria-checked]="grid() === g.ulid" (click)="pickGrid(g.ulid)">
                  <span>{{ g.name }}</span><small>{{ g.sizes[0] }} – {{ g.sizes[g.sizes.length - 1] }}</small></button>
              }
              <button type="button" class="pick" role="radio" [class.on]="!grid()" [attr.aria-checked]="!grid()" (click)="pickGrid('')"><span>{{ t('production.sizes.oneByOne') }}</span><small>{{ t('production.sizes.oneByOneHint') }}</small></button>
            </div>
          </div>
          @if (!grid()) {
            <div class="part">
              <div class="label">{{ t('production.sizes.sizes') }}</div>
              <div class="chips" role="group" [attr.aria-label]="t('production.sizes.sizes')">
                @for (s of catalogue(); track s.ulid) {
                  <button type="button" class="tog" [class.on]="chosen().includes(s.code)" [attr.aria-pressed]="chosen().includes(s.code)" (click)="toggle(s.code)">{{ s.code }}</button>
                }
              </div>
            </div>
          }
          @if (chosen().length > 0) {
            <div class="part">
              <div class="label">{{ t('production.sizes.quantities') }}
                <span class="fill">{{ t('production.sizes.fillAll') }} <input type="number" min="0" inputmode="numeric" [value]="fill()" (input)="fill.set($any($event.target).value)" (keydown.enter)="applyFill()" [attr.aria-label]="t('production.sizes.fillAll')" />
                  <button type="button" (click)="applyFill()" [disabled]="!fill()">{{ t('production.sizes.apply') }}</button></span>
              </div>
              <div class="run">
                @for (size of chosen(); track size) {
                  <label class="cell"><span class="size">{{ size }}</span>
                    <input type="number" min="0" inputmode="numeric" [value]="qty()[size] || ''" placeholder="0" (input)="setQty(size, $any($event.target).value)" [attr.aria-label]="t('production.sizes.piecesOf', { size })" /></label>
                }
                <div class="cell total"><span class="size">{{ t('production.sizes.totalShort') }}</span><span class="qty">{{ total() | dwNumber }}</span></div>
              </div>
              <p class="dw-faint note">{{ t('production.sizes.totalHint') }}</p>
            </div>
          }
        }
      </div>
      <div class="dw-dialog-foot">
        @if (data.sizes.length > 0) { <button pButton type="button" text severity="danger" class="clear" (click)="save(true)" [disabled]="busy()">{{ t('production.sizes.clear') }}</button> }
        <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
        <button pButton type="button" (click)="save(false)" [disabled]="busy() || chosen().length === 0">{{ t('common.save') }}</button>
      </div>
    </ng-container>
  `,
  styles: `
    .dw-dialog-body { inline-size: min(620px, 90vw); display: grid; gap: var(--dw-4); }
    .part { display: grid; gap: var(--dw-2); }
    .label { display: flex; align-items: center; gap: var(--dw-2); flex-wrap: wrap; font-size: 13px; font-weight: 600; color: var(--dw-ink-2); }
    .chips { display: flex; flex-wrap: wrap; gap: 6px; }
    .pick { all: unset; box-sizing: border-box; cursor: pointer; display: grid; gap: 1px; padding: 8px 14px; border-radius: var(--dw-radius); background: var(--dw-surface);
      box-shadow: inset 0 0 0 1px var(--dw-line-strong); transition: box-shadow 100ms, background 100ms; }
    .pick span { font-size: 13.5px; font-weight: 650; color: var(--dw-ink); } .pick small { font-size: 11.5px; color: var(--dw-ink-3); }
    .pick:hover { box-shadow: inset 0 0 0 1px var(--dw-primary); }
    .pick.on { background: var(--dw-primary-soft); box-shadow: inset 0 0 0 2px var(--dw-primary); } .pick.on span { color: var(--dw-primary-strong); }
    .pick:focus-visible, .tog:focus-visible { outline: 2px solid var(--dw-focus); outline-offset: 2px; }
    .tog { all: unset; box-sizing: border-box; cursor: pointer; min-inline-size: 42px; padding: 6px 10px; border-radius: var(--dw-radius); text-align: center; font-size: 13px; font-weight: 650;
      background: var(--dw-surface); color: var(--dw-ink-2); box-shadow: inset 0 0 0 1px var(--dw-line-strong); }
    .tog.on { background: var(--dw-primary); color: var(--dw-on-primary); box-shadow: none; }
    .fill { display: inline-flex; align-items: center; gap: 6px; margin-inline-start: auto; font-weight: 500; color: var(--dw-ink-3); font-size: 12.5px; }
    .fill input { inline-size: 64px; block-size: 28px; padding: 0 6px; border: 1px solid var(--dw-line-strong); border-radius: var(--dw-radius-sm); font: inherit; text-align: center; color: var(--dw-ink); background: var(--dw-surface); }
    .fill button { all: unset; cursor: pointer; font-weight: 600; color: var(--dw-primary); } .fill button:disabled { color: var(--dw-ink-3); cursor: default; }
    .run { display: flex; max-inline-size: 100%; overflow-x: auto; border: 1px solid var(--dw-line-strong); border-radius: var(--dw-radius); background: var(--dw-surface); }
    .cell { display: grid; grid-template-rows: auto 1fr; min-inline-size: 64px; flex: 1 0 64px; border-inline-start: 1px solid var(--dw-line); }
    .cell:first-child { border-inline-start: 0; }
    .cell .size { padding: 6px 8px; background: var(--dw-surface-2); font-size: 12px; font-weight: 700; color: var(--dw-ink-2); text-align: center; }
    .cell input { box-sizing: border-box; inline-size: 100%; min-inline-size: 0; block-size: 44px; border: 0; background: transparent; font: inherit; font-size: 16px; font-weight: 650;
      text-align: center; color: var(--dw-ink); font-variant-numeric: tabular-nums; outline: none; }
    .cell input:focus { background: var(--dw-primary-soft); box-shadow: inset 0 -2px 0 var(--dw-primary); }
    .cell input::placeholder { color: var(--dw-ink-3); font-weight: 500; }
    .cell.total { flex: 0 0 76px; } .cell.total .size { background: var(--dw-primary-soft); color: var(--dw-primary-strong); }
    .cell.total .qty { display: grid; place-items: center; font-size: 16px; font-weight: 700; color: var(--dw-primary-strong); font-variant-numeric: tabular-nums; }
    .note { margin: 0; font-size: 12px; }
    .empty { display: grid; justify-items: center; gap: var(--dw-2); padding: var(--dw-4); color: var(--dw-ink-3); text-align: center; } .empty dw-icon { font-size: 28px; } .empty p { margin: 0; }
    .clear { margin-inline-end: auto; }
  `,
})
export class OfSizesDialog {
  protected readonly data = dialogData<ManufacturingOrder>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);
  private readonly lists = inject(ProductionLists);
  protected readonly grids = toSignal(this.lists.sizeGrids(), { initialValue: [] as SizeGrid[] });
  protected readonly catalogue = toSignal(this.lists.sizes(), { initialValue: [] as Size[] });
  protected readonly busy = signal(false);
  protected readonly fill = signal('');
  protected readonly grid = signal(this.data.size_grid?.ulid ?? '');
  private readonly picked = signal<string[]>(this.data.size_grid ? [] : this.data.sizes.map((line) => line.size));
  protected readonly qty = signal<Record<string, number>>(Object.fromEntries(this.data.sizes.map((line) => [line.size, line.quantity])));
  /** The sizes the OF will have: the grid's, or those picked, in the company's order. */
  protected readonly chosen = computed(() => {
    const grid = this.grids().find((g) => g.ulid === this.grid());
    if (grid) {
      return grid.sizes;
    }
    const order = this.catalogue().map((s) => s.code);

    return [...this.picked()].sort((a, b) => order.indexOf(a) - order.indexOf(b));
  });
  protected readonly total = computed(() => this.chosen().reduce((sum, size) => sum + (this.qty()[size] || 0), 0));

  protected pickGrid(ulid: string): void {
    this.grid.set(ulid);
    if (!ulid) {
      this.picked.set(Object.keys(this.qty()).filter((size) => this.catalogue().some((s) => s.code === size)));
    }
  }

  protected toggle(code: string): void {
    this.picked.update((list) => (list.includes(code) ? list.filter((item) => item !== code) : [...list, code]));
  }

  protected setQty(size: string, value: string): void {
    const n = Math.max(0, Math.floor(Number(value) || 0));
    this.qty.update((all) => ({ ...all, [size]: n }));
  }

  /** The same quantity in every size, to start from. */
  protected applyFill(): void {
    const n = Math.max(0, Math.floor(Number(this.fill()) || 0));
    this.qty.update((all) => ({ ...all, ...Object.fromEntries(this.chosen().map((size) => [size, n])) }));
    this.fill.set('');
  }

  protected async save(clear: boolean): Promise<void> {
    this.busy.set(true);
    const body = clear ? { size_grid: null, sizes: [] } : { size_grid: this.grid() || null, sizes: this.chosen().map((size) => ({ size, quantity: this.qty()[size] || 0 })) };
    try {
      this.ref.close(await firstValueFrom(this.api.put<ManufacturingOrder>(`production/manufacturing-orders/${this.data.ulid}/sizes`, body)));
      this.toast.success('common.saved');
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}
