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
import { CheckboxComponent } from '../../ui/checkbox';
import { DIALOG_PARTS, DialogRef, Dialogs, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { SizeGrid } from './production-data';

/** "XS, S M;L" → ["XS", "S", "M", "L"]: sizes typed in one line, in order, each once. */
export const parseSizes = (text: string): string[] => [...new Set(text.split(/[\s,;]+/).map((size) => size.trim()).filter(Boolean))];

/** A grid of sizes: its name and its sizes in order, typed in one line. */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, ButtonDirective, InputText, ...FIELD, ...DIALOG_PARTS, CheckboxComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data ? 'production.sizes.edit' : 'production.sizes.new') }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <div class="dw-form">
            <dw-field class="dw-span-2" [label]="t('production.sizes.name')"><input pInputText formControlName="name" maxlength="80" required [placeholder]="t('production.sizes.namePlaceholder')" /></dw-field>
            <dw-field class="dw-span-2" [label]="t('production.sizes.sizes')">
              <input pInputText formControlName="sizes" required placeholder="XS, S, M, L, XL, XXL" />
              <small dwHint>{{ t('production.sizes.sizesHint') }}</small>
            </dw-field>
            <div class="dw-span-2 preview">@for (size of preview(); track size) { <span class="chip">{{ size }}</span> }</div>
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
    .dw-dialog-body { inline-size: min(520px, 86vw); }
    .preview { display: flex; flex-wrap: wrap; gap: 6px; min-block-size: 24px; }
    .chip { padding: 2px 10px; border-radius: 999px; background: var(--dw-surface-2); font-size: 13px; font-weight: 600; }
  `,
})
export class SizeGridDialog {
  protected readonly data = dialogData<SizeGrid | null>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);
  protected readonly busy = signal(false);
  protected readonly form = inject(FormBuilder).nonNullable.group({
    name: [this.data?.name ?? '', Validators.required],
    sizes: [(this.data?.sizes ?? []).join(', '), Validators.required],
    is_active: [this.data?.is_active ?? true],
  });
  private readonly typed = toSignal(this.form.controls.sizes.valueChanges, { initialValue: this.form.controls.sizes.value });
  protected readonly preview = computed(() => parseSizes(this.typed()));

  protected async submit(): Promise<void> {
    if (this.form.invalid || this.preview().length === 0) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const body = { name: this.form.controls.name.value.trim(), sizes: this.preview(), is_active: this.form.controls.is_active.value };
    try {
      this.ref.close(await firstValueFrom(this.data ? this.api.put<SizeGrid>(`production/size-grids/${this.data.ulid}`, body) : this.api.post<SizeGrid>('production/size-grids', body)));
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

/** The company's size grids, in the catalogue. */
@Component({
  selector: 'dw-size-grids',
  imports: [TranslocoDirective, ButtonDirective, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <div class="head">
        <p class="dw-muted">{{ t('production.sizes.intro') }}</p>
        @if (canManage) { <button pButton type="button" (click)="edit(null)"><dw-icon>add</dw-icon>{{ t('production.sizes.new') }}</button> }
      </div>
      @if (grids().length === 0) {
        <p class="empty">{{ t('production.sizes.empty') }}</p>
      } @else {
        <table class="dw-table">
          <thead><tr><th>{{ t('production.sizes.name') }}</th><th>{{ t('production.sizes.sizes') }}</th><th class="num">{{ t('production.sizes.used') }}</th><th></th></tr></thead>
          <tbody>
            @for (grid of grids(); track grid.ulid) {
              <tr>
                <td><strong>{{ grid.name }}</strong>@if (!grid.is_active) { <span class="dw-faint"> · {{ t('common.inactive') }}</span> }</td>
                <td>@for (size of grid.sizes; track size) { <span class="chip">{{ size }}</span> }</td>
                <td class="num">{{ t('production.sizes.ofCount', { count: grid.orders }) }}</td>
                <td class="num">@if (canManage) { <button pButton text rounded size="small" severity="secondary" class="dw-icon-btn" type="button" (click)="edit(grid)" [attr.aria-label]="t('common.edit')" [title]="t('common.edit')"><dw-icon>edit</dw-icon></button> }</td>
              </tr>
            }
          </tbody>
        </table>
      }
    </ng-container>
  `,
  styles: `
    .head { display: flex; justify-content: space-between; align-items: center; gap: var(--dw-3); flex-wrap: wrap; padding: var(--dw-2) var(--dw-3); border-block-end: 1px solid var(--dw-line); }
    .head p { margin: 0; font-size: 13px; }
    .empty { margin: 0; padding: var(--dw-5) var(--dw-4); text-align: center; color: var(--dw-ink-3); }
    table { inline-size: 100%; } .num { text-align: end; white-space: nowrap; }
    .chip { display: inline-block; margin: 2px 4px 2px 0; padding: 1px 9px; border-radius: 999px; background: var(--dw-surface-2); font-size: 12px; font-weight: 600; }
  `,
})
export class SizeGridsComponent {
  private readonly api = inject(Api);
  private readonly dialog = inject(Dialogs);
  protected readonly canManage = inject(Session).canEditProduction();
  protected readonly grids = signal<SizeGrid[]>([]);

  constructor() {
    this.load();
  }

  private load(): void {
    this.api.get<SizeGrid[]>('production/size-grids', { with_inactive: true }).subscribe((grids) => this.grids.set(grids));
  }

  protected edit(grid: SizeGrid | null): void {
    this.dialog.open<SizeGridDialog, SizeGrid | null, SizeGrid>(SizeGridDialog, { data: grid }).afterClosed().subscribe((saved) => saved && this.load());
  }
}

