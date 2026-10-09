import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { TranslocoDirective } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { Textarea } from 'primeng/textarea';

import { Api } from '../../core/api/api';
import { ToleranceRule, ToleranceRuleSnapshot } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { ReferenceData } from '../../core/data/reference';
import { Toast } from '../../core/feedback/toast';
import { LocalizedPipe, NumberPipe } from '../../core/format/pipes';
import { CheckboxComponent } from '../../ui/checkbox';
import { Confirm } from '../../ui/confirm';
import { DIALOG_PARTS, DialogRef, Dialogs, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { IconComponent } from '../../ui/icon';
import { StatusComponent } from '../../ui/page';
import { SELECT } from '../../ui/select';
import { StateComponent } from '../../ui/state';

const PCT = Validators.pattern(/^\d{1,3}([.,]\d{1,2})?$/);
const num = (value: string): string | null => (value.trim() === '' ? null : value.replace(',', '.'));

/** How precise a rule is: the most precise one applies, so the list reads from the particular to the general. */
export function precision(rule: Pick<ToleranceRule, 'waste_type' | 'treatment_channel'>): 'type_channel' | 'type' | 'channel' | 'company' {
  return rule.waste_type ? (rule.treatment_channel ? 'type_channel' : 'type') : rule.treatment_channel ? 'channel' : 'company';
}

/** What a rule says when it is read back: a limit that is lower than the one before it can never be reached. */
export function ruleProblem(warning: string, blocking: string): 'none' | 'order' | null {
  const [low, high] = [num(warning), num(blocking)];
  if (low === null && high === null) {
    return 'none';
  }

  return low !== null && high !== null && Number(high) < Number(low) ? 'order' : null;
}

@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, LocalizedPipe, ButtonDirective, InputText, Textarea, CheckboxComponent, ...FIELD, ...SELECT, ...DIALOG_PARTS],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ t(data ? 'tolerance.rules.edit' : 'tolerance.rules.new') }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <p class="dw-muted note">{{ t('tolerance.rules.dialogHint') }}</p>
          <div class="dw-form">
            <dw-field [label]="t('fields.wasteType')">
              <dw-select formControlName="waste_type">
                <dw-option [value]="null" [label]="t('tolerance.rules.anyType')" />
                @for (type of types(); track type.ulid) { <dw-option [value]="type.ulid" [label]="type.name + ' (' + t('units.' + (type.unit ?? 'kg'), { count: 2 }) + ')'" /> }
              </dw-select>
            </dw-field>
            <dw-field [label]="t('tolerance.rules.channel')">
              <dw-select formControlName="treatment_channel">
                <dw-option [value]="null" [label]="t('tolerance.rules.anyChannel')" />
                @for (channel of vocabulary()?.treatment_channels ?? []; track channel.code) { <dw-option [value]="channel.code" [label]="channel.name | dwLocalized" /> }
              </dw-select>
            </dw-field>
            <dw-field [label]="t('tolerance.rules.warning')">
              <input pInputText formControlName="warning_pct" inputmode="decimal" />
              <small dwHint>{{ t('tolerance.rules.warningHint') }}</small>
            </dw-field>
            <dw-field [label]="t('tolerance.rules.blocking')">
              <input pInputText formControlName="blocking_pct" inputmode="decimal" />
              <small dwHint>{{ t('tolerance.rules.blockingHint') }}</small>
            </dw-field>
            @if (problem(); as why) { <p class="dw-span-2 bad" role="alert">{{ t('tolerance.rules.problem.' + why) }}</p> }
            <dw-field [label]="t('tolerance.rules.direction')">
              <dw-select formControlName="direction" required>
                @for (direction of directions; track direction) { <dw-option [value]="direction" [label]="t('tolerance.rules.directions.' + direction)" /> }
              </dw-select>
            </dw-field>
            <dw-field [label]="t('tolerance.rules.basis')">
              <dw-select formControlName="basis" required>
                <dw-option value="operational" [label]="t('tolerance.basis.operational')" /><dw-option value="legal" [label]="t('tolerance.basis.legal')" />
              </dw-select>
            </dw-field>
            @if (form.controls.basis.value === 'legal') {
              <dw-field class="dw-span-2" [label]="t('tolerance.rules.reference')">
                <input pInputText formControlName="reference" maxlength="255" required />
                <small dwHint>{{ t('tolerance.rules.referenceHint') }}</small>
              </dw-field>
            }
            <dw-field class="dw-span-2" [label]="t('fields.notes')"><textarea pTextarea formControlName="notes" rows="2" maxlength="1000"></textarea></dw-field>
            <dw-checkbox class="dw-span-2" formControlName="is_active">{{ t('tolerance.rules.active') }}</dw-checkbox>
          </div>
        </div>
        <div class="dw-dialog-foot">
          <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
          <button pButton type="submit" [disabled]="busy() || problem() !== null">{{ t('common.save') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: '.dw-dialog-body { min-width: min(620px, 84vw); } .note { margin-block-end: var(--dw-3); font-size: 13px; } .bad { color: var(--dw-danger); font-weight: 600; margin: 0; }',
})
export class ToleranceRuleDialog {
  protected readonly data = dialogData<ToleranceRule | null>();
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly reference = inject(ReferenceData);
  private readonly ref = inject(DialogRef);

  protected readonly types = toSignal(this.reference.wasteTypes(), { initialValue: [] });
  protected readonly vocabulary = toSignal(this.reference.vocabulary());
  protected readonly directions = ['both', 'excess', 'shortage'] as const;
  protected readonly busy = signal(false);
  protected readonly form = inject(FormBuilder).nonNullable.group({
    waste_type: [(this.data?.waste_type?.ulid ?? null) as string | null],
    treatment_channel: [(this.data?.treatment_channel ?? null) as string | null],
    warning_pct: [this.data?.warning_pct ? String(Number(this.data.warning_pct)) : '', PCT],
    blocking_pct: [this.data?.blocking_pct ? String(Number(this.data.blocking_pct)) : '', PCT],
    direction: [this.data?.direction ?? ('both' as ToleranceRule['direction'])],
    basis: [this.data?.basis ?? ('operational' as ToleranceRule['basis'])],
    reference: [this.data?.reference ?? ''],
    notes: [this.data?.notes ?? ''],
    is_active: [this.data?.is_active ?? true],
  });
  private readonly value = toSignal(this.form.valueChanges, { initialValue: this.form.getRawValue() });
  protected readonly problem = computed(() => ruleProblem(this.value().warning_pct ?? '', this.value().blocking_pct ?? ''));

  protected async submit(): Promise<void> {
    const value = this.form.getRawValue();
    if (value.basis === 'legal' && value.reference.trim() === '') {
      this.form.controls.reference.setErrors({ required: true });
    }
    if (this.form.invalid || this.problem() !== null) {
      this.form.markAllAsTouched();

      return;
    }
    this.busy.set(true);
    const body = { ...value, warning_pct: num(value.warning_pct), blocking_pct: num(value.blocking_pct), reference: value.basis === 'legal' ? value.reference.trim() : null, notes: value.notes.trim() || null };
    try {
      this.ref.close(await firstValueFrom(this.data ? this.api.put<ToleranceRule>(`tolerance-rules/${this.data.ulid}`, body) : this.api.post<ToleranceRule>('tolerance-rules', body)));
      this.toast.success('tolerance.rules.saved');
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
 * The tolerances of the company: for each waste type and way of handling it, the gap accepted between what left
 * and what was received. Shown from the most precise rule to the default that stands below all of them.
 */
@Component({
  selector: 'dw-tolerances',
  imports: [TranslocoDirective, NumberPipe, StateComponent, StatusComponent, IconComponent, ButtonDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <div class="intro">
        <p class="dw-muted">{{ t('tolerance.rules.intro') }}</p>
        @if (canManage) { <button pButton type="button" (click)="edit(null)"><dw-icon>add</dw-icon>{{ t('tolerance.rules.new') }}</button> }
      </div>
      <dw-state [loading]="loading()" [error]="error()" (retry)="load()">
        <div class="scroll">
          <table class="dw-table">
            <thead>
              <tr>
                <th>{{ t('tolerance.rules.appliesTo') }}</th><th>{{ t('tolerance.rules.channel') }}</th><th class="dw-num">{{ t('tolerance.rules.warning') }}</th>
                <th class="dw-num">{{ t('tolerance.rules.blocking') }}</th><th>{{ t('tolerance.rules.direction') }}</th><th>{{ t('tolerance.rules.basis') }}</th><th></th>
              </tr>
            </thead>
            <tbody>
              @for (rule of sorted(); track rule.ulid) {
                <tr [class.off]="!rule.is_active">
                  <td>
                    @if (rule.waste_type; as type) { <strong>{{ type.name }}</strong><span class="dw-faint">&nbsp;· {{ t('units.' + (type.unit ?? 'kg'), { count: 2 }) }}</span> } @else { {{ t('tolerance.rules.anyType') }} }
                    @if (!rule.is_active) { <dw-status kind="member" value="suspended" tone="neutral" [label]="t('common.inactive')" /> }
                  </td>
                  <td>{{ rule.treatment_channel ? t('channels.' + rule.treatment_channel) : t('tolerance.rules.anyChannel') }}</td>
                  <td class="dw-num">{{ rule.warning_pct | dwNumber: 2 : ' %' }}</td>
                  <td class="dw-num">{{ rule.blocking_pct | dwNumber: 2 : ' %' }}</td>
                  <td>{{ t('tolerance.rules.directions.' + rule.direction) }}</td>
                  <td>{{ t('tolerance.basis.' + rule.basis) }}@if (rule.reference) { <div class="dw-faint">{{ rule.reference }}</div> }</td>
                  <td class="dw-num">
                    @if (canManage) {
                      <button pButton text type="button" (click)="edit(rule)">{{ t('common.edit') }}</button>
                      <button pButton text severity="danger" type="button" (click)="remove(rule)">{{ t('common.delete') }}</button>
                    }
                  </td>
                </tr>
              }
              @if (fallback(); as rule) {
                <tr class="fallback">
                  <td>{{ t('tolerance.rules.fallback') }}</td><td>{{ t('tolerance.rules.anyChannel') }}</td>
                  <td class="dw-num">{{ rule.warning_pct | dwNumber: 2 : ' %' }}</td><td class="dw-num">—</td><td>{{ t('tolerance.rules.directions.both') }}</td>
                  <td colspan="2" class="dw-faint">{{ t('tolerance.source.' + rule.source, { channel: '' }) }}</td>
                </tr>
              }
            </tbody>
          </table>
        </div>
        <p class="dw-faint foot">{{ t('tolerance.rules.history') }}</p>
      </dw-state>
    </ng-container>
  `,
  styles: `
    .intro { display: flex; align-items: flex-start; gap: var(--dw-3); padding: var(--dw-3) var(--dw-4); } .intro p { flex: 1; margin: 0; max-width: 78ch; }
    .scroll { overflow-x: auto; } tr.off td { color: var(--dw-ink-3); } tr.fallback td { background: var(--dw-surface-2); }
    .foot { padding: var(--dw-3) var(--dw-4); margin: 0; font-size: 12.5px; }
  `,
})
export class TolerancesComponent implements OnInit {
  private readonly api = inject(Api);
  private readonly dialog = inject(Dialogs);
  private readonly confirm = inject(Confirm);
  private readonly toast = inject(Toast);

  protected readonly canManage = inject(Session).can('waste_types.manage');
  protected readonly rules = signal<ToleranceRule[]>([]);
  protected readonly fallback = signal<ToleranceRuleSnapshot | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);
  protected readonly sorted = computed(() => {
    const rank = { type_channel: 0, type: 1, channel: 2, company: 3 };

    return [...this.rules()].sort((a, b) => rank[precision(a)] - rank[precision(b)] || (a.waste_type?.name ?? '').localeCompare(b.waste_type?.name ?? ''));
  });

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.raw<{ data: ToleranceRule[]; meta?: { default: ToleranceRuleSnapshot } }>('tolerance-rules').subscribe({
      next: (response) => {
        this.rules.set(response.data);
        this.fallback.set(response.meta?.default ?? null);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.error.set(error);
        this.loading.set(false);
      },
    });
  }

  protected edit(rule: ToleranceRule | null): void {
    this.dialog.open<ToleranceRuleDialog, ToleranceRule | null, ToleranceRule>(ToleranceRuleDialog, { data: rule }).afterClosed().subscribe((saved) => saved && this.load());
  }

  protected async remove(rule: ToleranceRule): Promise<void> {
    if (!(await this.confirm.ask({ title: this.toast.text('tolerance.rules.deleteTitle'), message: this.toast.text('tolerance.rules.deleteHint'), danger: true }))) {
      return;
    }
    try {
      await firstValueFrom(this.api.delete(`tolerance-rules/${rule.ulid}`));
      this.load();
    } catch (error) {
      this.toast.error(error);
    }
  }
}
