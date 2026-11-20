import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { TranslocoDirective, TranslocoService } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { Textarea } from 'primeng/textarea';

import { Api } from '../../core/api/api';
import { SCRAP_CATEGORIES, ScrapReason } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { Toast } from '../../core/feedback/toast';
import { KgPipe, MoneyPipe } from '../../core/format/pipes';
import { CheckboxComponent } from '../../ui/checkbox';
import { Confirm } from '../../ui/confirm';
import { DIALOG_PARTS, DialogRef, Dialogs, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { MenuAction, MenuComponent } from '../../ui/menu';
import { SegmentedComponent } from '../../ui/more';
import { PageHeaderComponent } from '../../ui/page';
import { ScrapReasonComponent } from '../../ui/scrap-reason';
import { SELECT } from '../../ui/select';
import { StateComponent } from '../../ui/state';

/** A palette that reads well on light surfaces and stays apart for colour-blind readers, in a fixed order. */
const PRESETS = ['#E5484D', '#F76B15', '#F5A524', '#30A46C', '#12A594', '#0090FF', '#3E63DD', '#8E4EC6', '#D6409F', '#8D8D86'];
const HEX = /^#[0-9a-fA-F]{6}$/;

/** One scrap reason: code and label as the plant uses them, its family of causes, its colour. */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, ButtonDirective, InputText, Textarea, CheckboxComponent, ScrapReasonComponent, ...SELECT, ...FIELD, ...DIALOG_PARTS],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data ? 'scrapReasons.edit' : 'scrapReasons.new') }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="save()" novalidate>
        <div class="dw-dialog-body">
          <div class="preview"><dw-scrap-reason [reason]="preview()" /></div>
          <div class="two">
            <dw-field [label]="t('scrapReasons.code')"><input pInputText formControlName="code" maxlength="30" placeholder="UE24" class="dw-mono" required /></dw-field>
            <dw-field [label]="t('scrapReasons.category')">
              <dw-select formControlName="category" clearable [placeholder]="t('scrapReasons.noCategory')">
                <dw-option [value]="null" [label]="t('scrapReasons.noCategory')" />
                @for (category of categories; track category) { <dw-option [value]="category" [label]="t('scrapReasons.categories.' + category)" /> }
              </dw-select>
              <small dwHint>{{ t('scrapReasons.categoryHint') }}</small>
            </dw-field>
          </div>
          <dw-field [label]="t('scrapReasons.label')"><input pInputText formControlName="label" maxlength="150" required /></dw-field>
          <dw-field [label]="t('scrapReasons.color')">
            <div class="colors">
              @for (preset of presets; track preset) {
                <button type="button" class="chip" [class.on]="form.value.color === preset" [style.background]="preset" [attr.aria-label]="preset" [title]="preset" (click)="pick(preset)"></button>
              }
              <label class="custom" [title]="t('scrapReasons.color')"><input type="color" [value]="form.value.color || '#8D8D86'" (input)="pick($any($event.target).value)" /></label>
              <input pInputText class="hex dw-mono" formControlName="color" maxlength="7" placeholder="#RRGGBB" />
            </div>
          </dw-field>
          <dw-field [label]="t('scrapReasons.description')"><textarea pTextarea formControlName="description" rows="2" maxlength="500"></textarea></dw-field>
          @if (data) { <dw-checkbox [checked]="form.value.is_active ?? true" (checkedChange)="form.controls.is_active.setValue($event)">{{ t('scrapReasons.active') }}</dw-checkbox> }
        </div>
        <div class="dw-dialog-foot">
          <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
          <button pButton type="submit" [disabled]="busy()">{{ t('common.save') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: `
    .dw-dialog-body { display: grid; gap: var(--dw-3); inline-size: min(560px, 90vw); }
    .two { display: grid; gap: var(--dw-3); grid-template-columns: 160px 1fr; }
    .preview { display: flex; justify-content: center; padding: 18px; border-radius: var(--dw-radius); background: var(--dw-surface-2); }
    .colors { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
    .chip { all: unset; inline-size: 24px; block-size: 24px; border-radius: 7px; cursor: pointer; box-shadow: inset 0 0 0 1px rgb(13 20 27 / 12%); transition: transform 120ms; }
    .chip:hover { transform: scale(1.08); } .chip.on { box-shadow: 0 0 0 2px var(--dw-surface), 0 0 0 4px var(--dw-ink); }
    .chip:focus-visible { outline: 2px solid var(--dw-focus); outline-offset: 2px; }
    .custom { inline-size: 24px; block-size: 24px; border-radius: 7px; overflow: hidden; cursor: pointer; background: conic-gradient(#E5484D, #F5A524, #30A46C, #0090FF, #8E4EC6, #E5484D); }
    .custom input { opacity: 0; inline-size: 100%; block-size: 100%; cursor: pointer; }
    .hex { inline-size: 110px; }
  `,
})
export class ScrapReasonDialog {
  protected readonly data = dialogData<ScrapReason | undefined>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);
  protected readonly busy = signal(false);
  protected readonly categories = SCRAP_CATEGORIES;
  protected readonly presets = PRESETS;
  protected readonly form = inject(FormBuilder).group({
    code: [this.data?.code ?? '', [Validators.required, Validators.maxLength(30)]],
    label: [this.data?.label ?? '', [Validators.required, Validators.maxLength(150)]],
    category: [this.data?.category ?? null],
    color: [this.data?.color ?? PRESETS[0], [Validators.pattern(HEX)]],
    description: [this.data?.description ?? ''],
    is_active: [this.data?.is_active ?? true],
  });
  private readonly value = signal(this.form.getRawValue());
  protected readonly preview = computed(() => {
    const v = this.value();
    return { ulid: '', code: (v.code || 'UE··').toUpperCase(), label: v.label || '…', category: v.category ?? null, color: HEX.test(v.color ?? '') ? v.color : null };
  });

  constructor() {
    this.form.valueChanges.subscribe(() => this.value.set(this.form.getRawValue()));
  }

  protected pick(color: string): void {
    this.form.controls.color.setValue(color.toUpperCase());
  }

  protected async save(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    this.busy.set(true);
    const v = this.form.getRawValue();
    const body = { code: v.code, label: v.label, category: v.category, color: v.color || null, description: v.description?.trim() || null, ...(this.data ? { is_active: v.is_active } : {}) };
    try {
      const saved = await firstValueFrom(this.data ? this.api.put<ScrapReason>(`scrap-reasons/${this.data.ulid}`, body) : this.api.post<ScrapReason>('scrap-reasons', body));
      this.toast.success('scrapReasons.saved');
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

type Period = 'year' | 'last12' | 'all';

/**
 * The company's scrap reasons (motifs de rebut): the list lots choose from, and what each weighs — a Pareto of the
 * scrapped weight, each reason in its colour, so the few reasons that make most of the waste stand out.
 */
@Component({
  imports: [TranslocoDirective, ButtonDirective, IconComponent, PageHeaderComponent, StateComponent, MenuComponent, SegmentedComponent, CheckboxComponent, ScrapReasonComponent, KgPipe, MoneyPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('scrapReasons.title')" [subtitle]="t('scrapReasons.subtitle')">
        @if (canManage) { <button pButton type="button" (click)="edit()"><dw-icon>add</dw-icon>{{ t('scrapReasons.new') }}</button> }
      </dw-page-header>
      <dw-state [loading]="loading() && reasons().length === 0" [error]="error()" [empty]="!loading() && reasons().length === 0" icon="report"
        [emptyTitle]="t('scrapReasons.empty')" [emptyHint]="t('scrapReasons.emptyHint')" (retry)="load()">
        <div class="dw-stack">
          @if (pareto().length) {
            <section class="dw-panel">
              <div class="dw-panel-head">
                <h2 class="dw-h2">{{ t('scrapReasons.pareto') }}</h2>
                <dw-segmented [options]="[{ value: 'last12', label: t('period.12m') }, { value: 'year', label: t('period.year') }, { value: 'all', label: t('scrapReasons.allTime') }]"
                  [value]="period()" (valueChange)="setPeriod($any($event))" [ariaLabel]="t('scrapReasons.pareto')" />
              </div>
              <div class="dw-panel-body pareto" role="list">
                @for (row of pareto(); track row.reason.ulid) {
                  <div class="bar-row" role="listitem" [style.--c]="row.reason.color || 'var(--dw-ink-3)'">
                    <dw-scrap-reason [reason]="row.reason" />
                    <div class="track" aria-hidden="true"><div class="fill" [style.inline-size.%]="row.width"></div></div>
                    <span class="kg">{{ row.reason.weight_kg | dwKg }}</span>
                    <span class="pct">{{ row.share }} %<small class="dw-faint" [title]="t('scrapReasons.cumulative')">{{ row.cumulative }} %</small></span>
                    <span class="lots dw-faint">{{ row.reason.lots }} {{ t('scrapReasons.lots').toLowerCase() }}</span>
                    @if (row.reason.material_value; as value) { <span class="money">{{ value.amount | dwMoney: value.currency }}</span> } @else { <span></span> }
                  </div>
                }
              </div>
            </section>
          }
          <section class="dw-panel">
            <div class="dw-panel-head">
              <h2 class="dw-h2">{{ t('scrapReasons.title') }}</h2>
              <dw-checkbox [checked]="withInactive()" (checkedChange)="withInactive.set($event); load()">{{ t('scrapReasons.showInactive') }}</dw-checkbox>
            </div>
            <table class="dw-table">
              <thead><tr>
                @if (canManage) { <th class="order"><span class="dw-sr-only">{{ t('scrapReasons.moveUp') }}</span></th> }
                <th>{{ t('scrapReasons.label') }}</th><th>{{ t('scrapReasons.category') }}</th>
                <th class="num">{{ t('scrapReasons.lots') }}</th><th class="num">{{ t('scrapReasons.weight') }}</th>
                @if (seesMoney()) { <th class="num">{{ t('scrapReasons.value') }}</th> }
                <th></th>
              </tr></thead>
              <tbody>
                @for (reason of reasons(); track reason.ulid; let first = $first; let last = $last) {
                  <tr [class.off]="!reason.is_active">
                    @if (canManage) {
                      <td class="order">
                        <button type="button" class="move" [disabled]="first || busy()" (click)="move(reason, -1)" [attr.aria-label]="t('scrapReasons.moveUp')"><dw-icon>keyboard_arrow_up</dw-icon></button>
                        <button type="button" class="move" [disabled]="last || busy()" (click)="move(reason, 1)" [attr.aria-label]="t('scrapReasons.moveDown')"><dw-icon>keyboard_arrow_down</dw-icon></button>
                      </td>
                    }
                    <td><dw-scrap-reason [reason]="reason" />@if (!reason.is_active) { <span class="dw-faint off-tag">{{ t('scrapReasons.inactive') }}</span> }
                      @if (reason.description) { <div class="dw-faint desc">{{ reason.description }}</div> }</td>
                    <td>{{ reason.category ? t('scrapReasons.categories.' + reason.category) : '—' }}</td>
                    <td class="num">{{ reason.lots }}</td>
                    <td class="num">{{ reason.weight_kg | dwKg }}</td>
                    @if (seesMoney()) { <td class="num">@if (reason.material_value; as value) { {{ value.amount | dwMoney: value.currency }} } @else { — }</td> }
                    <td class="act">@if (canManage) {
                      <button pButton text rounded severity="secondary" size="small" class="dw-icon-btn" type="button" (click)="menu.open($event, actions(reason))" [attr.aria-label]="t('common.actions')"><dw-icon>more_vert</dw-icon></button>
                    }</td>
                  </tr>
                }
              </tbody>
            </table>
          </section>
        </div>
      </dw-state>
      <dw-menu #menu />
    </ng-container>
  `,
  styles: `
    .pareto { display: grid; gap: 10px; }
    .bar-row { display: grid; grid-template-columns: minmax(200px, 300px) 1fr 90px 52px 70px 110px; align-items: center; gap: 14px; }
    .bar-row dw-scrap-reason { justify-self: start; max-inline-size: 100%; }
    .track { position: relative; block-size: 14px; border-radius: 4px; background: var(--dw-surface-2); }
    .fill { block-size: 100%; border-radius: 0 4px 4px 0; background: var(--c); transition: inline-size 500ms cubic-bezier(.2, .8, .2, 1); }
    .kg, .pct, .money { text-align: end; font-variant-numeric: tabular-nums; } .pct { font-weight: 650; display: grid; line-height: 1.2; } .pct small { font-weight: 500; font-size: 11.5px; } .kg { color: var(--dw-ink-2); }
    .lots { font-size: 12.5px; text-align: end; }
    @media (max-width: 900px) { .bar-row { grid-template-columns: 1fr 70px 52px; } .bar-row .track { grid-column: 1 / -1; grid-row: 2; } .lots, .money { display: none; } }
    td.order { inline-size: 1%; white-space: nowrap; }
    .move { all: unset; display: inline-grid; place-items: center; inline-size: 22px; block-size: 22px; border-radius: 6px; color: var(--dw-ink-3); cursor: pointer; }
    .move:hover:not(:disabled) { background: var(--dw-surface-2); color: var(--dw-ink); } .move:disabled { opacity: .3; cursor: default; }
    .move:focus-visible { outline: 2px solid var(--dw-focus); }
    tr.off dw-scrap-reason { opacity: .55; }
    .off-tag { margin-inline-start: 8px; font-size: 12px; }
    .desc { font-size: 12.5px; margin-block-start: 4px; }
    td.act { inline-size: 1%; text-align: end; }
  `,
})
export class ScrapReasonsPage {
  private readonly api = inject(Api);
  private readonly dialogs = inject(Dialogs);
  private readonly confirm = inject(Confirm);
  private readonly toast = inject(Toast);
  private readonly session = inject(Session);
  protected readonly canManage = this.session.can('waste_types.manage');
  protected readonly reasons = signal<ScrapReason[]>([]);
  protected readonly loading = signal(false);
  protected readonly busy = signal(false);
  protected readonly error = signal<unknown>(null);
  protected readonly withInactive = signal(false);
  protected readonly period = signal<Period>('last12');
  protected readonly seesMoney = computed(() => this.reasons().some((reason) => reason.material_value !== null));
  private readonly transloco = inject(TranslocoService);

  /** Reasons that weigh something, from the heaviest: each bar its share of the scrapped weight, the tick the running share. */
  protected readonly pareto = computed(() => {
    const rows = this.reasons().filter((reason) => Number(reason.weight_kg) > 0).sort((a, b) => Number(b.weight_kg) - Number(a.weight_kg));
    const total = rows.reduce((sum, reason) => sum + Number(reason.weight_kg), 0) || 1;
    const max = Number(rows[0]?.weight_kg ?? 1) || 1;
    let running = 0;
    return rows.map((reason) => {
      const weight = Number(reason.weight_kg);
      running += weight;
      return { reason, width: (weight / max) * 100, share: Math.round((weight / total) * 1000) / 10, cumulative: Math.round(Math.min(1, running / total) * 1000) / 10 };
    });
  });

  constructor() {
    this.load();
  }

  protected setPeriod(period: Period | null): void {
    this.period.set(period ?? 'last12');
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);
    const today = new Date();
    const iso = (date: Date) => date.toISOString().slice(0, 10);
    const from = this.period() === 'all' ? null : this.period() === 'year' ? `${today.getFullYear()}-01-01` : iso(new Date(today.getFullYear() - 1, today.getMonth(), today.getDate() + 1));
    this.api.get<ScrapReason[]>('scrap-reasons', { with_inactive: this.withInactive(), from }).subscribe({
      next: (reasons) => { this.reasons.set(reasons); this.loading.set(false); },
      error: (error: unknown) => { this.error.set(error); this.loading.set(false); },
    });
  }

  protected edit(reason?: ScrapReason): void {
    this.dialogs.open(ScrapReasonDialog, { data: reason }).afterClosed().subscribe((saved) => saved && this.load());
  }

  protected actions(reason: ScrapReason): MenuAction[] {
    return [
      { label: this.transloco.translate('common.edit'), icon: 'edit', run: () => this.edit(reason) },
      { label: this.transloco.translate(reason.is_active ? 'scrapReasons.deactivate' : 'scrapReasons.activate'), icon: reason.is_active ? 'block' : 'check_circle',
        run: () => void this.setActive(reason, !reason.is_active) },
      { separator: true },
      { label: this.transloco.translate('scrapReasons.delete'), icon: 'delete', danger: true, disabled: reason.lots > 0, run: () => void this.remove(reason) },
    ];
  }

  protected async move(reason: ScrapReason, step: -1 | 1): Promise<void> {
    const list = [...this.reasons()];
    const from = list.indexOf(reason);
    const to = from + step;
    if (to < 0 || to >= list.length) {
      return;
    }
    [list[from], list[to]] = [list[to], list[from]];
    this.reasons.set(list);
    this.busy.set(true);
    try {
      await firstValueFrom(this.api.put('scrap-reasons/order', { reasons: list.map((r) => r.ulid) }));
    } catch (error) {
      this.toast.error(error);
      this.load();
    } finally {
      this.busy.set(false);
    }
  }

  private async setActive(reason: ScrapReason, active: boolean): Promise<void> {
    try {
      await firstValueFrom(this.api.put(`scrap-reasons/${reason.ulid}`, { is_active: active }));
      this.toast.success('scrapReasons.saved');
      this.load();
    } catch (error) {
      this.toast.error(error);
    }
  }

  private async remove(reason: ScrapReason): Promise<void> {
    const sure = await this.confirm.ask({ title: this.transloco.translate('scrapReasons.delete'), message: this.transloco.translate('scrapReasons.deleteConfirm', { code: reason.code }), danger: true });
    if (!sure) {
      return;
    }
    try {
      await firstValueFrom(this.api.delete(`scrap-reasons/${reason.ulid}`));
      this.load();
    } catch (error) {
      this.toast.error(error);
    }
  }
}
