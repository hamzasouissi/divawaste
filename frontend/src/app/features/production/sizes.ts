import { CdkDrag, CdkDragDrop, CdkDropList, moveItemInArray } from '@angular/cdk/drag-drop';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
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

const CHIP = `
  .chips { display: flex; flex-wrap: wrap; gap: 6px; }
  .pick { all: unset; box-sizing: border-box; cursor: pointer; min-inline-size: 44px; padding: 6px 12px; border-radius: 999px; text-align: center; font-size: 13px; font-weight: 600;
    background: var(--dw-surface); color: var(--dw-ink-2); box-shadow: inset 0 0 0 1px var(--dw-line-strong); transition: background 100ms, color 100ms, box-shadow 100ms; }
  .pick:hover { box-shadow: inset 0 0 0 1px var(--dw-primary); color: var(--dw-ink); }
  .pick.on { background: var(--dw-primary); color: var(--dw-on-primary); box-shadow: none; }
  .pick:focus-visible { outline: 2px solid var(--dw-focus); outline-offset: 2px; }
`;

/** A grid: a name and some of the company's sizes, ticked; they keep the company's order. */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, ButtonDirective, InputText, ...FIELD, ...DIALOG_PARTS, CheckboxComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data.grid ? 'production.sizes.edit' : 'production.sizes.new') }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <div class="dw-form">
            <dw-field class="dw-span-2" [label]="t('production.sizes.name')"><input pInputText formControlName="name" maxlength="80" required [placeholder]="t('production.sizes.namePlaceholder')" /></dw-field>
            <div class="dw-span-2 part">
              <div class="label">{{ t('production.sizes.gridSizes') }} <span class="dw-faint">· {{ t('production.sizes.picked', { count: picked().length }) }}</span></div>
              <div class="chips">
                @for (s of data.sizes; track s.ulid) {
                  <button type="button" class="pick" [class.on]="picked().includes(s.code)" [attr.aria-pressed]="picked().includes(s.code)" (click)="toggle(s.code)">{{ s.code }}</button>
                }
              </div>
              @if (tried() && picked().length === 0) { <small class="dw-field-error" role="alert">{{ t('production.sizes.pickOne') }}</small> }
            </div>
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
    .dw-dialog-body { inline-size: min(540px, 86vw); }
    .part { display: grid; gap: var(--dw-2); } .label { font-size: 13px; font-weight: 600; color: var(--dw-ink-2); }
  ` + CHIP,
})
export class SizeGridDialog {
  protected readonly data = dialogData<{ grid: SizeGrid | null; sizes: Size[] }>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);
  protected readonly busy = signal(false);
  protected readonly tried = signal(false);
  protected readonly picked = signal<string[]>(this.data.grid?.sizes ?? []);
  protected readonly form = inject(FormBuilder).nonNullable.group({
    name: [this.data.grid?.name ?? '', Validators.required],
    is_active: [this.data.grid?.is_active ?? true],
  });

  protected toggle(code: string): void {
    this.picked.update((list) => (list.includes(code) ? list.filter((item) => item !== code) : [...list, code]));
  }

  protected async submit(): Promise<void> {
    this.tried.set(true);
    if (this.form.invalid || this.picked().length === 0) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const sizes = this.data.sizes.map((s) => s.code).filter((code) => this.picked().includes(code));
    const body = { name: this.form.controls.name.value.trim(), sizes, is_active: this.form.controls.is_active.value };
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
 * The company's sizes and its grids, side by side in the catalogue. Sizes are typed once ("XS S M L XL"), kept in
 * order (moved with the arrows); a grid ticks some of them. A size a grid or an OF uses cannot be removed.
 */
@Component({
  selector: 'dw-size-grids',
  imports: [TranslocoDirective, ButtonDirective, InputText, IconComponent, CdkDropList, CdkDrag],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <div class="layout">
        <section class="block">
          <header>
            <h3 class="dw-h3">{{ t('production.sizes.catalogueTitle') }}</h3>
            <p class="dw-muted">{{ t('production.sizes.catalogueIntro') }}</p>
          </header>
          <div class="list" cdkDropList cdkDropListOrientation="mixed" [cdkDropListDisabled]="!canManage" (cdkDropListDropped)="drop($event)">
            @for (s of sizes(); track s.ulid; let i = $index, first = $first, last = $last) {
              <span class="size" cdkDrag [class.movable]="canManage" [attr.tabindex]="canManage ? 0 : null" [title]="canManage ? t('production.sizes.dragHint') : ''">
                @if (canManage && !first) { <button type="button" class="mini mv" (click)="move(i, -1)" [attr.aria-label]="t('production.sizes.moveBefore', { size: s.code })" [title]="t('production.sizes.moveBefore', { size: s.code })"><dw-icon>chevron_left</dw-icon></button> }
                <b>{{ s.code }}</b>
                @if (canManage && !last) { <button type="button" class="mini mv" (click)="move(i, 1)" [attr.aria-label]="t('production.sizes.moveAfter', { size: s.code })" [title]="t('production.sizes.moveAfter', { size: s.code })"><dw-icon>chevron_right</dw-icon></button> }
                @if (canManage && s.grids + s.orders === 0) {
                  <button type="button" class="mini x" (click)="remove(s)" [attr.aria-label]="t('production.sizes.remove', { size: s.code })" [title]="t('production.sizes.remove', { size: s.code })"><dw-icon>close</dw-icon></button>
                }
              </span>
            } @empty {
              <p class="empty">{{ t('production.sizes.noSize') }}</p>
            }
          </div>
          @if (canManage) {
            <form class="add" (submit)="$event.preventDefault(); add()">
              <input pInputText [value]="draft()" (input)="draft.set($any($event.target).value)" [placeholder]="t('production.sizes.addPlaceholder')" [attr.aria-label]="t('production.sizes.addPlaceholder')" />
              <button pButton type="submit" [disabled]="parsed().length === 0 || busy()"><dw-icon>add</dw-icon>{{ t('production.sizes.add') }}</button>
            </form>
            <small class="dw-faint hint">{{ t('production.sizes.addHint') }}</small>
          }
        </section>

        <section class="block">
          <header class="row">
            <div>
              <h3 class="dw-h3">{{ t('production.sizes.title') }}</h3>
              <p class="dw-muted">{{ t('production.sizes.intro') }}</p>
            </div>
            @if (canManage) { <button pButton type="button" size="small" (click)="edit(null)" [disabled]="sizes().length === 0"><dw-icon>add</dw-icon>{{ t('production.sizes.new') }}</button> }
          </header>
          @for (grid of grids(); track grid.ulid) {
            <div class="grid" [class.off]="!grid.is_active">
              <div class="grid-main">
                <strong>{{ grid.name }}</strong>
                <span class="dw-faint">{{ grid.is_active ? t('production.sizes.ofCount', { count: grid.orders }) : t('common.inactive') }}</span>
              </div>
              <div class="chips">@for (size of grid.sizes; track size) { <span class="chip">{{ size }}</span> }</div>
              @if (canManage) { <button pButton text rounded size="small" severity="secondary" class="dw-icon-btn" type="button" (click)="edit(grid)" [attr.aria-label]="t('common.edit')" [title]="t('common.edit')"><dw-icon>edit</dw-icon></button> }
            </div>
          } @empty {
            <p class="empty">{{ t(sizes().length === 0 ? 'production.sizes.sizesFirst' : 'production.sizes.empty') }}</p>
          }
        </section>
      </div>
    </ng-container>
  `,
  styles: `
    .layout { display: grid; grid-template-columns: minmax(0, 5fr) minmax(0, 7fr); gap: var(--dw-4); padding: var(--dw-4); }
    @media (max-width: 900px) { .layout { grid-template-columns: minmax(0, 1fr); } }
    .block { display: grid; align-content: start; gap: var(--dw-3); padding: var(--dw-4); border-radius: var(--dw-radius-lg); background: var(--dw-surface-2); }
    header h3 { margin: 0; } header p { margin: 2px 0 0; font-size: 13px; }
    header.row { display: flex; justify-content: space-between; align-items: flex-start; gap: var(--dw-3); }
    .list { display: flex; flex-wrap: wrap; gap: 6px; }
    .size { display: inline-flex; align-items: center; gap: 2px; padding: 4px 6px 4px 12px; border-radius: 999px; background: var(--dw-surface); box-shadow: var(--dw-shadow); font-size: 13.5px; }
    .size b { font-weight: 650; padding-inline-end: 6px; }
    .size:not(:has(.mini)) { padding-inline-end: 12px; }
    .mini { all: unset; cursor: pointer; display: inline-grid; place-items: center; inline-size: 22px; block-size: 22px; border-radius: 50%; color: var(--dw-ink-3); }
    .mini dw-icon { font-size: 16px; } .mini:hover { background: var(--dw-surface-3); color: var(--dw-ink); } .mini.x:hover { background: var(--dw-danger-soft); color: var(--dw-danger); }
    .mini:focus-visible { outline: 2px solid var(--dw-focus); }
    /* Moved by dragging; the arrows are the keyboard's way, shown when the chip has the focus. */
    .size.movable { cursor: grab; } .size:focus-visible { outline: 2px solid var(--dw-focus); outline-offset: 2px; }
    .mini.mv { display: none; } .size:focus-within .mini.mv { display: inline-grid; }
    @media (hover: hover) { .mini.x { opacity: 0; } .size:hover .mini.x, .size:focus-within .mini.x { opacity: 1; } }
    .size.cdk-drag-preview { box-shadow: var(--dw-shadow-pop); cursor: grabbing; }
    .size.cdk-drag-placeholder { opacity: 0.35; }
    .list.cdk-drop-list-dragging .size:not(.cdk-drag-placeholder) { transition: transform 180ms var(--dw-ease); }
    .add { display: flex; gap: var(--dw-2); } .add input { flex: 1 1 auto; min-inline-size: 0; }
    .hint { font-size: 12px; }
    .grid { display: grid; grid-template-columns: minmax(120px, 0.6fr) minmax(0, 1fr) auto; align-items: center; gap: var(--dw-3); padding: 10px 12px; border-radius: var(--dw-radius); background: var(--dw-surface); box-shadow: var(--dw-shadow); }
    .grid.off { opacity: 0.6; }
    .grid-main { display: grid; } .grid-main span { font-size: 12px; }
    .chips { display: flex; flex-wrap: wrap; gap: 4px; }
    .chip { padding: 2px 9px; border-radius: 999px; background: var(--dw-surface-3); font-size: 12px; font-weight: 600; }
    .empty { margin: 0; padding: var(--dw-3) 0; color: var(--dw-ink-3); font-size: 13px; }
    @media (max-width: 560px) { .grid { grid-template-columns: minmax(0, 1fr) auto; } .grid .chips { grid-column: 1 / -1; grid-row: 2; } }
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

  protected move(index: number, step: -1 | 1): void {
    const list = [...this.sizes()];
    [list[index], list[index + step]] = [list[index + step], list[index]];
    void this.order(list);
  }

  private async order(list: Size[]): Promise<void> {
    this.sizes.set(list);
    try {
      this.sizes.set(await firstValueFrom(this.api.put<Size[]>('production/sizes/order', { sizes: list.map((s) => s.ulid) })));
      this.load();
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
      .subscribe((saved) => saved && this.load());
  }
}

/**
 * The sizes of an OF, from its page: a grid (all its sizes), or sizes ticked one by one; then the pieces of each.
 * Their total becomes the quantity planned.
 */
@Component({
  imports: [TranslocoDirective, ButtonDirective, ...FIELD, ...DIALOG_PARTS, NumberPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t('production.sizes.ofTitle', { of: data.order_number }) }}</dw-dialog-title>
      <div class="dw-dialog-body">
        @if (catalogue().length === 0 && grids().length === 0) {
          <p class="dw-muted empty">{{ t('production.sizes.sizesFirst') }}</p>
        } @else {
          <div class="dw-form">
            <div class="dw-span-2 part">
              <div class="label">{{ t('production.sizes.from') }}</div>
              <div class="chips" role="radiogroup" [attr.aria-label]="t('production.sizes.from')">
                @for (g of grids(); track g.ulid) {
                  <button type="button" class="pick" role="radio" [class.on]="grid() === g.ulid" [attr.aria-checked]="grid() === g.ulid" [title]="g.sizes.join(' ')" (click)="pickGrid(g.ulid)">{{ g.name }}</button>
                }
                <button type="button" class="pick" role="radio" [class.on]="!grid()" [attr.aria-checked]="!grid()" (click)="pickGrid('')">{{ t('production.sizes.oneByOne') }}</button>
              </div>
            </div>
            @if (!grid()) {
              <div class="dw-span-2 part">
              <div class="label">{{ t('production.sizes.sizes') }}</div>
              <div class="chips" role="group" [attr.aria-label]="t('production.sizes.sizes')">
                @for (s of catalogue(); track s.ulid) {
                  <button type="button" class="pick" [class.on]="chosen().includes(s.code)" [attr.aria-pressed]="chosen().includes(s.code)" (click)="toggle(s.code)">{{ s.code }}</button>
                }
              </div>
              </div>
            }
            @if (chosen().length > 0) {
              <div class="dw-span-2 boxes">
                @for (size of chosen(); track size) {
                  <label class="box"><span>{{ size }}</span>
                    <input type="number" min="0" inputmode="numeric" [value]="qty()[size] ?? ''" (input)="setQty(size, $any($event.target).value)" [attr.aria-label]="t('production.sizes.piecesOf', { size })" /></label>
                }
              </div>
              <p class="dw-span-2 total">{{ t('production.sizes.total', { count: (total() | dwNumber) }) }} <span class="dw-faint">· {{ t('production.sizes.totalHint') }}</span></p>
            }
          </div>
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
    .dw-dialog-body { inline-size: min(560px, 88vw); }
    .empty { margin: 0; }
    .part { display: grid; gap: var(--dw-2); } .label { font-size: 13px; font-weight: 600; color: var(--dw-ink-2); }
    .boxes { display: grid; grid-template-columns: repeat(auto-fill, minmax(76px, 1fr)); gap: var(--dw-2); }
    .box { display: grid; gap: 4px; text-align: center; font-size: 13px; font-weight: 650; color: var(--dw-ink-2); }
    .box input { box-sizing: border-box; inline-size: 100%; min-inline-size: 0; block-size: 40px; border: 1px solid var(--dw-line-strong); border-radius: var(--dw-radius);
      font: inherit; font-size: 15px; font-weight: 600; text-align: center; color: var(--dw-ink); background: var(--dw-surface); font-variant-numeric: tabular-nums; }
    .box input:focus { outline: none; border-color: var(--dw-focus); box-shadow: 0 0 0 3px var(--dw-focus-soft); }
    .total { margin: 0; font-size: 14px; } .total span { font-size: 12.5px; }
    .clear { margin-inline-end: auto; }
  ` + CHIP,
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
  protected readonly grid = signal(this.data.size_grid?.ulid ?? '');
  private readonly picked = signal<string[]>(this.data.size_grid ? [] : this.data.sizes.map((line) => line.size));
  protected readonly qty = signal<Record<string, number>>(Object.fromEntries(this.data.sizes.map((line) => [line.size, line.quantity])));
  /** The sizes the OF will have: the grid's, or those ticked, in the company's order. */
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
