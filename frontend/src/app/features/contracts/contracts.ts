import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormArray, FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { TranslocoDirective, TranslocoService } from '@jsverse/transloco';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { Textarea } from 'primeng/textarea';
import { catchError, firstValueFrom, of } from 'rxjs';

import { Api } from '../../core/api/api';
import { DirectoryProvider, ExternalPartner } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { ReferenceData } from '../../core/data/reference';
import { Toast } from '../../core/feedback/toast';
import { DatePipe, KgPipe, MoneyPipe } from '../../core/format/pipes';
import { Confirm } from '../../ui/confirm';
import { Column, TABLE } from '../../ui/data-table';
import { DateComponent } from '../../ui/date';
import { DIALOG_PARTS, DialogRef, Dialogs, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { FilterBarComponent } from '../../ui/filter-bar';
import { HelpComponent } from '../../ui/help';
import { IconComponent } from '../../ui/icon';
import { StepsComponent } from '../../ui/steps';
import { MoreComponent } from '../../ui/more';
import { PriceComponent } from '../../ui/price';
import { PageHeaderComponent, StatusComponent } from '../../ui/page';
import { SELECT } from '../../ui/select';
import { StateComponent } from '../../ui/state';

type Direction = 'revenue' | 'cost';
type Frequency = 'on_demand' | 'weekly' | 'biweekly' | 'monthly' | 'quarterly';
type State = 'draft' | 'upcoming' | 'active' | 'expired' | 'terminated';

interface ContractPrice {
  waste_type: { ulid: string; name: string } | null;
  pricing_mode: 'per_kg' | 'per_tonne' | 'flat';
  unit_price: string;
  direction: Direction;
}

/** One charge of a contract: when it is due (each pickup, each tonne received, each month begun, once) and who pays. */
export interface ContractCharge {
  label: string;
  amount: string;
  basis: 'per_pickup' | 'per_tonne' | 'per_month' | 'once';
  direction: Direction;
}

export interface Contract {
  ulid: string;
  contract_number: string;
  reference: string | null;
  title: string;
  status: 'draft' | 'active' | 'terminated';
  state: State;
  company: { ulid: string; legal_name: string; city: string | null } | null;
  party: { ulid: string; legal_name: string; city: string | null; external: boolean } | null;
  party_role: 'receiver' | 'transporter';
  site: { ulid: string; code: string; name: string } | null;
  starts_on: string;
  ends_on: string | null;
  frequency: Frequency;
  pickups_per_period: number | null;
  weekdays: string[];
  planned_pickups: number | null;
  prices: ContractPrice[];
  /** Charges beside the prices, as many as the contract has. */
  charges?: ContractCharge[];
  pickup_fee: string | null;
  pickup_fee_direction: Direction;
  monthly_fee: string | null;
  monthly_fee_direction: Direction;
  payment_terms_days: number | null;
  currency_code: string;
  notes: string | null;
  activated_at: string | null;
  terminated_at: string | null;
  termination_reason: string | null;
  progress: {
    pickups_done: number;
    pickups_open: number;
    planned_pickups: number | null;
    remaining: number | null;
    period: { starts_on: string | null; done: number | null; expected: number } | null;
    last_pickup_at: string | null;
    next_due_on: string | null;
    late: boolean;
    amounts: { lots: string; pickup_fees: string; monthly_fees: string; months: number; total: string };
  };
  pickups: { ulid: string; pickup_number: string; status: string; requested_date: string; confirmed_date: string | null; collected_at: string | null; received_weight_kg: string | null; contract_fees_amount: string | null }[] | null;
}

const FREQUENCIES: Frequency[] = ['on_demand', 'weekly', 'biweekly', 'monthly', 'quarterly'];
const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
const MONEY = [Validators.pattern(/^\d+([.,]\d{1,3})?$/)];
const decimal = (value: string): string | null => (value.trim() === '' ? null : value.replace(',', '.'));

/**
 * A contract with a receiver or a transporter: who, for which sites, from when to when, how often waste is collected,
 * the price of each waste type and the fees. Saved as a draft; activating it is a separate step.
 */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, ButtonDirective, InputText, Textarea, DateComponent, IconComponent, ...FIELD, ...SELECT, ...DIALOG_PARTS, MoreComponent, PriceComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ editing ? t('contracts.edit', { number: editing.contract_number }) : t('contracts.new') }}</dw-dialog-title>
      <!-- C-01: three short steps; each can be left for later, the contract is saved as a draft. -->
      <ol class="wizard" role="tablist">
        @for (label of stepLabels; track label; let i = $index) {
          <li><button type="button" role="tab" [attr.aria-selected]="step() === i" [class.on]="step() === i" [class.done]="step() > i" (click)="step.set(i)">
            <span class="n">{{ i + 1 }}</span>{{ t(label) }}</button></li>
        }
      </ol>
      <form [formGroup]="form" (ngSubmit)="submit(false)" novalidate>
        <div class="dw-dialog-body">
          @switch (step()) {
            @case (0) {
              <div class="dw-form">
                <dw-field class="dw-span-2" [label]="t('contracts.titleField')"><input pInputText formControlName="title" maxlength="191" required [placeholder]="t('contracts.titlePlaceholder')" /></dw-field>
                @if (!editing || editing.status === 'draft') {
                  <dw-field [label]="t('contracts.role')">
                    <dw-select formControlName="party_role">
                      <dw-option value="receiver" [label]="t('contracts.roles.receiver')" />
                      <dw-option value="transporter" [label]="t('contracts.roles.transporter')" />
                    </dw-select>
                  </dw-field>
                  <dw-field [label]="t('contracts.party')">
                    <dw-select formControlName="party" required>
                      @for (provider of providers(); track provider.ulid) { <dw-option [value]="'p:' + provider.ulid" [label]="provider.legal_name" /> }
                      @for (partner of partners(); track partner.ulid) { <dw-option [value]="'x:' + partner.ulid" [label]="partner.legal_name + ' — ' + t('partners.noAccount')" /> }
                    </dw-select>
                  </dw-field>
                }
                <dw-field [label]="t('contracts.startsOn')"><dw-date formControlName="starts_on" /></dw-field>
                <dw-field [label]="t('contracts.endsOn')"><dw-date formControlName="ends_on" /><small dwHint>{{ t('contracts.endsOnHint') }}</small></dw-field>
                <dw-more [summary]="t('contracts.moreSummary')">
                  <dw-field [label]="t('contracts.reference')"><input pInputText formControlName="reference" maxlength="100" /></dw-field>
                  <dw-field [label]="t('fields.site')">
                    <dw-select formControlName="site">
                      <dw-option [value]="null" [label]="t('common.allSites')" />
                      @for (site of sites; track site.ulid) { <dw-option [value]="site.ulid" [label]="site.name" /> }
                    </dw-select>
                  </dw-field>
                  <dw-field class="dw-span-2" [label]="t('fields.notes')"><textarea pTextarea formControlName="notes" rows="2" maxlength="5000"></textarea></dw-field>
                </dw-more>
              </div>
            }
            @case (1) {
              <div class="dw-stack-sm">
                <p class="dw-field-label">{{ t('contracts.frequencyQuestion') }}</p>
                <div class="choices" role="radiogroup" [attr.aria-label]="t('contracts.frequency')">
                  @for (f of frequencies; track f) {
                    <button type="button" role="radio" class="choice" [class.on]="frequency() === f" [attr.aria-checked]="frequency() === f" (click)="setFrequency(f)">
                      <strong>{{ t('contracts.frequencies.' + f) }}</strong><small>{{ t('contracts.frequencyHints.' + f) }}</small>
                    </button>
                  }
                </div>
                @if (frequency() === 'weekly' || frequency() === 'biweekly') {
                  <!-- A pickup on each chosen day: the number of pickups is the number of days, never a second figure to reconcile. -->
                  <fieldset class="days">
                    <legend class="dw-field-label">{{ t(frequency() === 'weekly' ? 'contracts.daysWeekly' : 'contracts.daysBiweekly') }}</legend>
                    @for (day of weekdays; track day) {
                      <button type="button" class="day" [class.on]="days().includes(day)" [attr.aria-pressed]="days().includes(day)" (click)="toggleDay(day, !days().includes(day))">{{ t('contracts.days.' + day) }}</button>
                    }
                  </fieldset>
                  <p class="summary">
                    <dw-icon aria-hidden="true">event_repeat</dw-icon>
                    @if (days().length > 0) { {{ t(frequency() === 'weekly' ? 'contracts.paceWeekly' : 'contracts.paceBiweekly', { count: days().length }) }} }
                    @else { {{ t('contracts.paceNoDay') }} }
                  </p>
                } @else if (frequency() === 'monthly' || frequency() === 'quarterly') {
                  <dw-field class="per" [label]="t(frequency() === 'monthly' ? 'contracts.perMonth' : 'contracts.perQuarter')">
                    <input pInputText formControlName="pickups_per_period" inputmode="numeric" placeholder="1" />
                  </dw-field>
                }
                @if (frequency() !== 'on_demand') {
                  <p class="dw-faint hint">{{ t('contracts.weekdaysHint') }}</p>
                }
                <dw-field class="per" [label]="t('contracts.plannedPickups')"><input pInputText formControlName="planned_pickups" inputmode="numeric" /><small dwHint>{{ t('contracts.plannedHint') }}</small></dw-field>
              </div>
            }
            @case (2) {
              <div class="dw-stack-sm">
                <p class="dw-field-label">{{ t('contracts.regimeQuestion') }}</p>
                <div class="choices three" role="radiogroup" [attr.aria-label]="t('contracts.regime')">
                  @for (r of regimes; track r) {
                    <button type="button" role="radio" class="choice" [class.on]="regime() === r" [attr.aria-checked]="regime() === r" (click)="setRegime(r)">
                      <strong>{{ t('contracts.regimes.' + r) }}</strong><small>{{ t('contracts.regimeHints.' + r) }}</small>
                    </button>
                  }
                </div>

                @if (regime() !== 'flat') {
                  <h3 class="dw-h3 section">{{ t('contracts.prices') }}</h3>
                  <p class="dw-faint hint">{{ t('contracts.pricesHint') }}</p>
                  @for (group of prices.controls; track group; let i = $index) {
                    <div class="price-line">
                      <dw-select class="type" [formControl]="$any(group.controls['waste_type'])" [ariaLabel]="t('fields.wasteType')">
                        <dw-option [value]="null" [label]="t('contracts.anyWaste')" />
                        @for (type of types(); track type.ulid) { <dw-option [value]="type.ulid" [label]="type.name" /> }
                      </dw-select>
                      <dw-price-input [direction]="$any(group.controls['direction'])" [mode]="$any(group.controls['pricing_mode'])" [amount]="$any(group.controls['unit_price'])" [currency]="currency" [required]="true" />
                      <button pButton text rounded severity="secondary" class="dw-icon-btn" type="button" (click)="prices.removeAt(i)" [attr.aria-label]="t('common.remove')" [title]="t('common.remove')"><dw-icon>close</dw-icon></button>
                    </div>
                  }
                  <div><button pButton severity="secondary" outlined type="button" (click)="addPrice()"><dw-icon>add</dw-icon>{{ t('contracts.addPrice') }}</button></div>
                }

                <h3 class="dw-h3 section">{{ t(regime() === 'weight' ? 'contracts.chargesExtra' : 'contracts.chargesFlat') }}</h3>
                <p class="dw-faint hint">{{ t(regime() === 'weight' ? 'contracts.chargesExtraHint' : 'contracts.chargesFlatHint') }}</p>
                @for (group of charges.controls; track group; let i = $index) {
                  <div class="charge-line">
                    <input pInputText class="label" [formControl]="$any(group.controls['label'])" [placeholder]="t('contracts.chargeLabel')" [attr.aria-label]="t('contracts.chargeLabel')" maxlength="120" />
                    <dw-price-input [direction]="$any(group.controls['direction'])" [amount]="$any(group.controls['amount'])" [currency]="currency" [required]="true" />
                    <dw-select class="basis" [formControl]="$any(group.controls['basis'])" [ariaLabel]="t('contracts.chargeBasis')">
                      @for (basis of bases; track basis) { <dw-option [value]="basis" [label]="t('contracts.bases.' + basis)" /> }
                    </dw-select>
                    <button pButton text rounded severity="secondary" class="dw-icon-btn" type="button" (click)="charges.removeAt(i)" [attr.aria-label]="t('common.remove')" [title]="t('common.remove')"><dw-icon>close</dw-icon></button>
                  </div>
                }
                <div><button pButton severity="secondary" outlined type="button" (click)="addCharge()"><dw-icon>add</dw-icon>{{ t('contracts.addCharge') }}</button></div>
                @if (regime() === 'flat' && charges.length === 0) { <small class="days-warn" role="alert">{{ t('contracts.flatNeedsAmount') }}</small> }

                <dw-field class="terms" [label]="t('contracts.paymentTerms')"><input pInputText formControlName="payment_terms_days" inputmode="numeric" placeholder="30" /><small dwHint>{{ t('contracts.paymentTermsHint') }}</small></dw-field>
              </div>
            }
          }
        </div>
        <div class="dw-dialog-foot">
          @if (step() > 0) { <button pButton type="button" text severity="secondary" (click)="step.set(step() - 1)"><dw-icon>arrow_back</dw-icon>{{ t('common.back') }}</button> }
          <span class="dw-spacer"></span>
          <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
          @if (step() < 2) {
            <button pButton type="button" severity="secondary" outlined [disabled]="busy()" (click)="submit(false)">{{ t(editing ? 'common.save' : 'contracts.saveDraft') }}</button>
            <button pButton type="button" (click)="next()">{{ t('common.next') }}<dw-icon>arrow_forward</dw-icon></button>
          } @else {
            <button pButton type="submit" [severity]="activatable ? 'secondary' : undefined" [outlined]="activatable" [disabled]="busy()">{{ t(editing ? 'common.save' : 'contracts.saveDraft') }}</button>
            <!-- C-03: put in force in the same gesture. -->
            @if (activatable) { <button pButton type="button" [disabled]="busy()" (click)="submit(true)"><dw-icon>verified</dw-icon>{{ t('contracts.saveAndActivate') }}</button> }
          }
        </div>
      </form>
    </ng-container>
  `,
  styles: `
    .dw-dialog-body { inline-size: min(780px, 92vw); min-block-size: 320px; }
    .choices { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 8px; } .choices.three { grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); }
    .choice { all: unset; box-sizing: border-box; cursor: pointer; display: grid; gap: 3px; padding: 12px 14px; border-radius: 12px; box-shadow: inset 0 0 0 1px var(--dw-line); background: var(--dw-surface); }
    .choice strong { font-size: 14px; font-weight: 620; } .choice small { font-size: 12.5px; line-height: 17px; color: var(--dw-ink-3); }
    .choice:hover { box-shadow: inset 0 0 0 1px var(--dw-primary); }
    .choice.on { box-shadow: inset 0 0 0 2px var(--dw-primary); background: var(--dw-primary-soft); } .choice:focus-visible { outline: 2px solid var(--dw-focus); outline-offset: 2px; }
    .summary { display: flex; align-items: center; gap: 8px; margin: 0; padding: 10px 12px; border-radius: 10px; background: var(--dw-surface-2); font-size: 14px; }
    .summary dw-icon { color: var(--dw-primary); font-size: 19px; } .per { max-inline-size: 280px; }
    .wizard { list-style: none; display: flex; gap: 6px; margin: 0; padding: 0 24px 6px; }
    .wizard button { all: unset; display: inline-flex; align-items: center; gap: 8px; padding: 7px 12px 7px 8px; border-radius: 999px; font-size: 13px; font-weight: 560; color: var(--dw-ink-3); cursor: pointer; }
    .wizard button:hover { background: var(--dw-surface-2); }
    .wizard button.on { background: var(--dw-primary-soft); color: var(--dw-primary); font-weight: 650; }
    .wizard .n { display: grid; place-items: center; width: 22px; height: 22px; border-radius: 50%; background: var(--dw-surface-3); font-size: 12px; font-weight: 700; }
    .wizard button.on .n { background: var(--dw-primary); color: #fff; } .wizard button.done .n { background: var(--dw-primary-line); color: var(--dw-primary-strong); }
    .wizard button:focus-visible { outline: 2px solid var(--dw-focus); outline-offset: 2px; }
    .days { display: flex; flex-wrap: wrap; gap: 6px; border: 0; padding: 0; margin: 0; } .days legend { inline-size: 100%; margin-block-end: 6px; }
    .days-hint, .days-warn { flex-basis: 100%; font-size: 12.5px; margin-block-start: 4px; } .days-warn { color: var(--dw-danger); }
    .day { all: unset; min-width: 44px; padding: 7px 10px; text-align: center; border-radius: 8px; border: 1px solid var(--dw-line-strong); font-size: 13px; font-weight: 560; cursor: pointer; }
    .day.on { background: var(--dw-primary); border-color: var(--dw-primary); color: #fff; } .day:focus-visible { outline: 2px solid var(--dw-focus); outline-offset: 2px; }
    .hint { margin: -4px 0 4px; font-size: 13px; }
    .price-line { display: grid; grid-template-columns: minmax(160px, 1fr) minmax(0, 2.2fr) auto; gap: 10px; align-items: center; padding: 8px 0; border-block-end: 1px solid var(--dw-line-soft); }
    .charge-line { display: grid; grid-template-columns: minmax(140px, 1.2fr) minmax(0, 1.6fr) minmax(130px, 0.9fr) auto; gap: 10px; align-items: center; padding: 8px 0; border-block-end: 1px solid var(--dw-line-soft); }
    @media (max-width: 720px) { .charge-line { grid-template-columns: 1fr auto; } }
    .section { margin-block-start: 14px; }
    .fee { display: grid; grid-template-columns: 180px minmax(0, 1fr); align-items: center; gap: 10px; }
    .terms { max-width: 260px; }
    @media (max-width: 640px) { .price-line, .fee { grid-template-columns: minmax(0, 1fr); } }
  `,
})
export class ContractDialog {
  private readonly given = dialogData<{ contract?: Contract; prefill?: { party: string; role: 'receiver' | 'transporter' } } | undefined>();
  protected readonly editing = this.given?.contract ?? null;
  private readonly fb = inject(FormBuilder);
  private readonly transloco = inject(TranslocoService);
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);
  private readonly session = inject(Session);

  protected readonly sites = this.session.sites();
  protected readonly frequencies = FREQUENCIES;
  protected readonly weekdays = WEEKDAYS;
  protected readonly currency = this.editing?.currency_code ?? this.session.company()?.currency_code ?? 'TND';
  protected readonly busy = signal(false);
  protected readonly types = toSignal(inject(ReferenceData).wasteTypes(), { initialValue: [] });
  protected readonly providers = toSignal(this.api.get<DirectoryProvider[]>('providers/directory').pipe(catchError(() => of([] as DirectoryProvider[]))), { initialValue: [] });
  protected readonly partners = toSignal(this.api.get<ExternalPartner[]>('external-partners').pipe(catchError(() => of([] as ExternalPartner[]))), { initialValue: [] });
  protected readonly days = signal<string[]>(this.editing?.weekdays ?? []);
  protected readonly frequency = signal<Frequency>(this.editing?.frequency ?? 'on_demand');
  protected readonly regimes = ['weight', 'flat', 'both'] as const;
  /** How the contract is paid: by the weight of the waste (prices per type), by fixed amounts, or both. */
  protected readonly regime = signal<'weight' | 'flat' | 'both'>(((): 'weight' | 'flat' | 'both' => {
    const prices = (this.editing?.prices ?? []).length > 0;
    const fixed = (this.editing?.charges ?? []).length > 0 || !!this.editing?.pickup_fee || !!this.editing?.monthly_fee;
    return prices && fixed ? 'both' : fixed ? 'flat' : 'weight';
  })());

  protected readonly form = this.fb.nonNullable.group({
    title: [this.editing?.title ?? '', Validators.required],
    party_role: [this.editing?.party_role ?? this.given?.prefill?.role ?? ('receiver' as 'receiver' | 'transporter')],
    party: [this.editing?.party ? `${this.editing.party.external ? 'x' : 'p'}:${this.editing.party.ulid}` : (this.given?.prefill?.party ?? ''), Validators.required],
    reference: [this.editing?.reference ?? ''],
    site: [this.editing?.site?.ulid ?? (null as string | null)],
    starts_on: [this.editing?.starts_on ?? new Date().toISOString().slice(0, 10), Validators.required],
    ends_on: [this.editing?.ends_on ?? ''],
    frequency: [this.editing?.frequency ?? ('monthly' as Frequency)],
    pickups_per_period: [String(this.editing?.pickups_per_period ?? 1), [Validators.pattern(/^\d{1,2}$/)]],
    planned_pickups: [this.editing?.planned_pickups ? String(this.editing.planned_pickups) : '', [Validators.pattern(/^\d{0,5}$/)]],
    pickup_fee: [this.editing?.pickup_fee ? String(Number(this.editing.pickup_fee)) : '', MONEY],
    pickup_fee_direction: [this.editing?.pickup_fee_direction ?? ('cost' as Direction)],
    monthly_fee: [this.editing?.monthly_fee ? String(Number(this.editing.monthly_fee)) : '', MONEY],
    monthly_fee_direction: [this.editing?.monthly_fee_direction ?? ('cost' as Direction)],
    payment_terms_days: [this.editing?.payment_terms_days !== null && this.editing?.payment_terms_days !== undefined ? String(this.editing.payment_terms_days) : '', [Validators.pattern(/^\d{0,3}$/)]],
    notes: [this.editing?.notes ?? ''],
  });
  protected readonly prices: FormArray<FormGroup> = this.fb.array((this.editing?.prices ?? []).map((price) => this.priceGroup(price)));
  protected readonly bases: ContractCharge['basis'][] = ['per_pickup', 'per_tonne', 'per_month', 'once'];
  // the fee per pickup and the monthly fee of older contracts are shown, and saved, as lines of the one list
  protected readonly charges: FormArray<FormGroup> = this.fb.array([
    ...(this.editing?.pickup_fee ? [this.chargeGroup({ label: this.transloco.translate('contracts.perPickupFee'), amount: this.editing.pickup_fee, basis: 'per_pickup', direction: this.editing.pickup_fee_direction })] : []),
    ...(this.editing?.monthly_fee ? [this.chargeGroup({ label: this.transloco.translate('contracts.perMonthFee'), amount: this.editing.monthly_fee, basis: 'per_month', direction: this.editing.monthly_fee_direction })] : []),
    ...(this.editing?.charges ?? []).map((charge) => this.chargeGroup(charge)),
  ]);

  protected readonly step = signal(0);
  protected readonly stepLabels = ['contracts.steps.who', 'contracts.steps.pickups', 'contracts.steps.money'];
  /** A new contract, or a draft, can be put in force as soon as it is saved. */
  protected readonly activatable = (!this.editing || this.editing.status === 'draft') && this.session.can('contracts.manage');

  /** The first step needs its party and dates before going on; the others are optional. */
  protected next(): void {
    if (this.step() === 0 && ['title', 'party', 'starts_on'].some((name) => this.form.get(name)?.invalid)) {
      ['title', 'party', 'starts_on'].forEach((name) => this.form.get(name)?.markAsTouched());
      return;
    }
    this.step.update((step) => Math.min(2, step + 1));
  }

  protected addPrice(): void {
    this.prices.push(this.priceGroup());
  }

  protected addCharge(): void {
    this.charges.push(this.chargeGroup());
  }

  private chargeGroup(charge?: ContractCharge): FormGroup {
    return this.fb.nonNullable.group({
      label: [charge?.label ?? '', [Validators.required, Validators.maxLength(120)]],
      amount: [charge ? String(Number(charge.amount)) : '', [Validators.required, ...MONEY]],
      basis: [charge?.basis ?? ('per_pickup' as ContractCharge['basis'])],
      direction: [charge?.direction ?? ('cost' as Direction)],
    });
  }

  protected setFrequency(frequency: Frequency): void {
    this.frequency.set(frequency);
    this.form.controls.frequency.setValue(frequency);
    if (frequency !== 'weekly' && frequency !== 'biweekly') {
      this.days.set([]);
    }
  }

  protected setRegime(regime: 'weight' | 'flat' | 'both'): void {
    this.regime.set(regime);
    if (regime !== 'weight' && this.charges.length === 0) {
      this.charges.push(this.chargeGroup({ label: this.transloco.translate('contracts.perPickupFee'), amount: '', basis: 'per_pickup', direction: 'cost' }));
    }
    if (regime !== 'flat' && this.prices.length === 0) {
      this.addPrice();
    }
  }

  protected toggleDay(day: string, checked: boolean): void {
    this.days.update((list) => (checked ? WEEKDAYS.filter((d) => d === day || list.includes(d)) : list.filter((d) => d !== day)));
  }

  protected async submit(activate: boolean): Promise<void> {
    if (this.form.invalid || (this.regime() !== 'flat' && this.prices.invalid) || this.charges.invalid || (this.regime() === 'flat' && this.charges.length === 0)) {
      this.form.markAllAsTouched();
      this.prices.markAllAsTouched();
      this.charges.markAllAsTouched();
      // Back to the step that holds what is missing.
      this.step.set(['title', 'party', 'starts_on', 'ends_on'].some((name) => this.form.get(name)?.invalid) ? 0 : this.prices.invalid || this.charges.invalid || this.charges.length === 0 ? 2 : this.step());

      return;
    }
    const value = this.form.getRawValue();
    const [kind, party] = value.party.split(':');
    const body: Record<string, unknown> = {
      title: value.title.trim(), reference: value.reference.trim() || null, site: value.site, starts_on: value.starts_on, ends_on: value.ends_on || null,
      frequency: value.frequency,
      // a pickup on each chosen day (weekly, every two weeks); a number for the month or the quarter
      pickups_per_period: value.frequency === 'on_demand' ? null : this.days().length > 0 ? this.days().length : Number(value.pickups_per_period || 1),
      weekdays: value.frequency === 'weekly' || value.frequency === 'biweekly' ? this.days() : [], planned_pickups: value.planned_pickups ? Number(value.planned_pickups) : null,
      pickup_fee: null, monthly_fee: null,
      payment_terms_days: value.payment_terms_days === '' ? null : Number(value.payment_terms_days), notes: value.notes.trim() || null,
      prices: this.regime() === 'flat' ? [] : this.prices.getRawValue().map((price) => ({ waste_type: price['waste_type'] ?? null, pricing_mode: price['pricing_mode'], unit_price: decimal(String(price['unit_price'])), direction: price['direction'] })),
      charges: this.charges.getRawValue().map((charge) => ({ label: String(charge['label']).trim(), amount: decimal(String(charge['amount'])), basis: charge['basis'], direction: charge['direction'] })),
    };
    if (!this.editing || this.editing.status === 'draft') {
      Object.assign(body, { party_role: value.party_role, provider: kind === 'p' ? party : null, external_partner: kind === 'x' ? party : null });
    }
    this.busy.set(true);
    try {
      let contract = await firstValueFrom(this.editing ? this.api.put<Contract>(`contracts/${this.editing.ulid}`, body) : this.api.post<Contract>('contracts', body));
      if (activate) {
        contract = await firstValueFrom(this.api.post<Contract>(`contracts/${contract.ulid}/activate`, {}));
        this.toast.success('contracts.activated');
      } else {
        this.toast.success(this.editing ? 'common.saved' : 'contracts.draftSaved', { number: contract.contract_number });
      }
      this.ref.close(contract);
    } catch (error) {
      if (!this.toast.applyValidation(this.form, error)) {
        this.toast.error(error);
      }
    } finally {
      this.busy.set(false);
    }
  }

  private priceGroup(price?: ContractPrice): FormGroup {
    return this.fb.group({
      waste_type: [price?.waste_type?.ulid ?? (null as string | null)],
      direction: [price?.direction ?? ('revenue' as Direction)],
      pricing_mode: [price?.pricing_mode ?? 'per_tonne'],
      unit_price: [price ? String(Number(price.unit_price)) : '', [Validators.required, ...MONEY]],
    });
  }
}

/**
 * The contracts of the company with its receivers and transporters — or, for a provider, the contracts it is a party
 * to. Each says how it stands: pickups done against what it plans, the next one due, a pickup that is late.
 */
@Component({
  imports: [TranslocoDirective, ButtonDirective, ...TABLE, ...SELECT, PageHeaderComponent, StatusComponent, IconComponent, HelpComponent, FilterBarComponent, DatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-page-header [title]="t('contracts.title')" [subtitle]="t(provider ? 'contracts.providerSubtitle' : 'contracts.subtitle')">
        @if (canManage) { <button pButton type="button" (click)="create()"><dw-icon>add</dw-icon>{{ t('contracts.new') }}</button> }
      </dw-page-header>
      <dw-help topic="contracts" />
      <div class="dw-panel">
        <dw-filter-bar [active]="state().length > 0 ? 1 : 0" (cleared)="state.set([]); load()">
          <dw-select [placeholder]="t('fields.status')" multiple [value]="state()" (valueChange)="state.set($any($event)); load()">
            @for (value of states; track value) { <dw-option [value]="value" [label]="t('status.contract.' + value)" /> }
          </dw-select>
        </dw-filter-bar>
        <dw-data-table [columns]="columns" [rows]="rows()" [loading]="loading()" [error]="error()" [clickable]="true" emptyIcon="contract"
          [emptyTitle]="t('contracts.emptyTitle')" [emptyHint]="t(provider ? 'contracts.providerEmptyHint' : 'contracts.emptyHint')" (rowClick)="open($event)" (retry)="load()">
          <ng-template dwCell="number" let-c><span class="dw-mono">{{ c.contract_number }}</span>@if (c.reference) { <div class="dw-faint">{{ c.reference }}</div> }</ng-template>
          <ng-template dwCell="party" let-c>{{ provider ? c.company?.legal_name : c.party?.legal_name }}<div class="dw-faint">{{ c.title }}</div></ng-template>
          <ng-template dwCell="role" let-c>{{ t('contracts.roles.' + c.party_role) }}</ng-template>
          <ng-template dwCell="dates" let-c>{{ c.starts_on | dwDate }} → {{ c.ends_on ? (c.ends_on | dwDate) : t('contracts.openEnded') }}</ng-template>
          <ng-template dwCell="schedule" let-c>{{ t('contracts.frequencies.' + c.frequency) }}@if (c.pickups_per_period && c.frequency !== 'on_demand') { · {{ t('contracts.timesPerPeriod', { count: c.pickups_per_period }) }} }</ng-template>
          <ng-template dwCell="progress" let-c>
            {{ c.progress.pickups_done }}@if (c.planned_pickups) { / {{ c.planned_pickups }} }
            @if (c.progress.late) { <div class="late">{{ t('contracts.late') }}</div> } @else if (c.progress.next_due_on) { <div class="dw-faint">{{ t('contracts.nextDue', { date: (c.progress.next_due_on | dwDate) }) }}</div> }
          </ng-template>
          <ng-template dwCell="state" let-c><dw-status kind="contract" [value]="c.state" /></ng-template>
          @if (canManage) { <button dwEmptyAction pButton type="button" (click)="create()"><dw-icon>add</dw-icon>{{ t('contracts.new') }}</button> }
        </dw-data-table>
      </div>
    </ng-container>
  `,
  styles: '.late { color: var(--dw-danger); font-weight: 600; font-size: 12px; }',
})
export class ContractsPage implements OnInit {
  private readonly api = inject(Api);
  private readonly router = inject(Router);
  private readonly dialog = inject(Dialogs);
  private readonly session = inject(Session);

  protected readonly provider = this.session.audience() === 'provider';
  protected readonly canManage = !this.provider && this.session.can('contracts.manage');
  protected readonly states: State[] = ['active', 'upcoming', 'draft', 'expired', 'terminated'];
  protected readonly state = signal<State[]>([]);
  protected readonly rows = signal<Contract[]>([]);
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);
  protected readonly columns: Column<Contract>[] = [
    { key: 'number', header: 'contracts.number' },
    { key: 'party', header: this.provider ? 'pickups.client' : 'contracts.party' },
    { key: 'role', header: 'contracts.role', hideBelow: 900 },
    { key: 'dates', header: 'contracts.period', hideBelow: 720 },
    { key: 'schedule', header: 'contracts.frequency', hideBelow: 1100 },
    { key: 'progress', header: 'contracts.pickupsDone' },
    { key: 'state', header: 'fields.status' },
  ];

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.get<Contract[]>('contracts', { filter: { state: this.state() } }).subscribe({
      next: (rows) => { this.rows.set(rows); this.loading.set(false); },
      error: (error: unknown) => { this.error.set(error); this.loading.set(false); },
    });
  }

  protected open(contract: Contract): void {
    void this.router.navigate([this.provider ? '/provider/contracts' : '/contracts', contract.ulid]);
  }

  protected create(): void {
    this.dialog.open<ContractDialog, { contract?: Contract }, Contract>(ContractDialog, { data: {} }).afterClosed()
      .subscribe((contract) => contract && void this.router.navigate(['/contracts', contract.ulid]));
  }
}

/**
 * One contract: its terms, how it stands, and the pickups made under it.
 */
@Component({
  imports: [TranslocoDirective, RouterLink, ButtonDirective, PageHeaderComponent, StatusComponent, StateComponent, IconComponent, DatePipe, KgPipe, MoneyPipe, StepsComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-state [loading]="loading()" [error]="error()" (retry)="load()">
        @if (contract(); as c) {
          <dw-page-header [title]="c.title" [subtitle]="c.contract_number + (c.reference ? ' · ' + c.reference : '') + ' · ' + ((provider ? c.company?.legal_name : c.party?.legal_name) ?? '')"
            [back]="provider ? '/provider/contracts' : '/contracts'" [backLabel]="t('contracts.title')">
            <dw-status dwTitleBadge kind="contract" [value]="c.state" />
            @if (canManage && c.status !== 'terminated') {
              <button pButton severity="secondary" outlined type="button" (click)="edit()"><dw-icon>edit</dw-icon>{{ t('common.edit') }}</button>
              @if (c.status === 'active') { <button pButton severity="danger" text type="button" (click)="terminate()">{{ t('contracts.terminate') }}</button> }
              @if (c.status === 'draft') { <button pButton type="button" [disabled]="busy()" (click)="activate()"><dw-icon>task_alt</dw-icon>{{ t('contracts.activate') }}</button> }
            }
            <!-- C-04: the contract knows its pace: the next pickup is asked with its provider and date already set. -->
            @if (!provider && c.state === 'active' && c.party_role === 'receiver' && c.party && session.canAnywhere('pickups.request')) {
              <a pButton routerLink="/pickups/new" [queryParams]="{ site: c.site?.ulid ?? null, provider: (c.party.external ? 'x:' : 'c:') + c.party.ulid, date: c.progress.next_due_on }">
                <dw-icon>local_shipping</dw-icon>{{ t('contracts.requestNext') }}</a>
            }
          </dw-page-header>
          <!-- G-07: draft, in force, ended — and what to do now. -->
          <dw-steps class="lifecycle" [steps]="contractSteps(t)" [current]="c.state === 'expired' || c.state === 'terminated' ? 'ended' : c.state === 'upcoming' ? 'active' : c.state"
            [stopped]="c.state === 'terminated' ? t('status.contract.terminated') : null" [finished]="c.state === 'expired'" [next]="t('contracts.next.' + c.state)" [tone]="c.state === 'terminated' ? 'danger' : 'normal'" />
          @if (c.progress.late) { <p class="banner bad">{{ t('contracts.lateHint', { date: (c.progress.next_due_on | dwDate) }) }}</p> }

          <div class="dw-stats">
            <div class="stat"><div class="label">{{ t('contracts.pickupsDone') }}</div><div class="value">{{ c.progress.pickups_done }}@if (c.planned_pickups) { <span class="dw-faint"> / {{ c.planned_pickups }}</span> }</div>
              @if (c.progress.remaining !== null) { <div class="hint">{{ t('contracts.remaining', { count: c.progress.remaining }) }}</div> }</div>
            @if (c.progress.period; as p) {
              <div class="stat"><div class="label">{{ t('contracts.thisPeriod') }}</div><div class="value">{{ p.done }} / {{ p.expected }}</div><div class="hint">{{ t('contracts.since', { date: (p.starts_on | dwDate) }) }}</div></div>
            }
            <div class="stat"><div class="label">{{ t('contracts.nextDueLabel') }}</div><div class="value" [class.bad]="c.progress.late">{{ c.progress.next_due_on ? (c.progress.next_due_on | dwDate) : '—' }}</div>
              <div class="hint">{{ c.progress.last_pickup_at ? t('contracts.lastPickup', { date: (c.progress.last_pickup_at | dwDate) }) : t('contracts.noPickupYet') }}</div></div>
            <div class="stat"><div class="label">{{ t('contracts.balance') }}</div><div class="value" [class.bad]="+c.progress.amounts.total < 0">{{ c.progress.amounts.total | dwMoney: c.currency_code }}</div>
              <div class="hint">{{ t(provider ? 'contracts.balanceProviderHint' : 'contracts.balanceHint') }}</div></div>
          </div>

          <div class="dw-split">
            <section class="dw-panel">
              <div class="dw-panel-head"><h2 class="dw-h2">{{ t('contracts.terms') }}</h2></div>
              <div class="dw-panel-body">
                <dl class="dw-facts">
                  <div><dt>{{ t(provider ? 'pickups.client' : 'contracts.party') }}</dt><dd>{{ provider ? c.company?.legal_name : c.party?.legal_name }}@if (!provider && c.party?.external) { <span class="dw-faint"> · {{ t('partners.noAccount') }}</span> }</dd></div>
                  <div><dt>{{ t('contracts.role') }}</dt><dd>{{ t('contracts.roles.' + c.party_role) }}</dd></div>
                  <div><dt>{{ t('fields.site') }}</dt><dd>{{ c.site?.name ?? t('common.allSites') }}</dd></div>
                  <div><dt>{{ t('contracts.period') }}</dt><dd>{{ c.starts_on | dwDate }} → {{ c.ends_on ? (c.ends_on | dwDate) : t('contracts.openEnded') }}</dd></div>
                  <div><dt>{{ t('contracts.frequency') }}</dt><dd>{{ t('contracts.frequencies.' + c.frequency) }}@if (c.pickups_per_period && c.frequency !== 'on_demand') { · {{ t('contracts.timesPerPeriod', { count: c.pickups_per_period }) }} }
                    @if (c.weekdays.length) { <div class="dw-faint">@for (d of c.weekdays; track d) { {{ t('contracts.days.' + d) }} } </div> }</dd></div>
                  <div><dt>{{ t('contracts.pickupFeeLabel') }}</dt><dd>@if (c.pickup_fee) { {{ c.pickup_fee | dwMoney: c.currency_code }} · {{ t('contracts.directions.' + c.pickup_fee_direction) }} } @else { — }</dd></div>
                  <div><dt>{{ t('contracts.monthlyFeeLabel') }}</dt><dd>@if (c.monthly_fee) { {{ c.monthly_fee | dwMoney: c.currency_code }} · {{ t('contracts.directions.' + c.monthly_fee_direction) }} } @else { — }</dd></div>
                  <div><dt>{{ t('contracts.paymentTerms') }}</dt><dd>{{ c.payment_terms_days !== null ? t('contracts.days30', { days: c.payment_terms_days }) : '—' }}</dd></div>
                  @if (c.termination_reason) { <div class="dw-span-2"><dt>{{ t('contracts.terminatedBecause') }}</dt><dd>{{ c.termination_reason }}</dd></div> }
                  @if (c.notes) { <div class="dw-span-2"><dt>{{ t('fields.notes') }}</dt><dd class="notes">{{ c.notes }}</dd></div> }
                </dl>
                <h3 class="dw-h3">{{ t('contracts.prices') }}</h3>
                <table class="plain">
                  <tbody>
                    @for (p of c.prices; track $index) {
                      <tr><td>{{ p.waste_type?.name ?? t('contracts.anyWaste') }}</td><td class="num"><strong>{{ p.unit_price | dwMoney: c.currency_code }}</strong>{{ p.pricing_mode === 'flat' ? ' / ' + t('contracts.lot') : t('marketplace.per.' + p.pricing_mode) }}</td>
                        <td>{{ t('contracts.directions.' + p.direction) }}</td></tr>
                    } @empty { <tr><td class="dw-faint">{{ t('contracts.noPrice') }}</td></tr> }
                  </tbody>
                </table>
                @if (c.charges?.length) {
                  <h3 class="dw-h3">{{ t('contracts.charges') }}</h3>
                  <table class="plain">
                    <tbody>
                      @for (charge of c.charges; track $index) {
                        <tr><td>{{ charge.label }}</td><td class="num"><strong>{{ charge.amount | dwMoney: c.currency_code }}</strong> {{ t('contracts.bases.' + charge.basis) }}</td>
                          <td>{{ t('contracts.directions.' + charge.direction) }}</td></tr>
                      }
                    </tbody>
                  </table>
                }
              </div>
            </section>

            <section class="dw-panel">
              <div class="dw-panel-head"><h2 class="dw-h2">{{ t('contracts.pickupsUnder', { count: c.pickups?.length ?? 0 }) }}</h2></div>
              <div class="dw-panel-body">
                <div class="scroll-x"><table class="plain">
                  <tbody>
                    @for (p of c.pickups ?? []; track p.ulid) {
                      <tr>
                        <td><a class="dw-mono" [routerLink]="[provider ? '/provider/pickups' : '/pickups', p.ulid]">{{ p.pickup_number }}</a></td>
                        <td>{{ (p.confirmed_date ?? p.requested_date) | dwDate }}</td>
                        <td><dw-status kind="pickup" [value]="p.status" /></td>
                        <td class="num">{{ p.received_weight_kg | dwKg }}</td>
                        <td class="num">@if (p.contract_fees_amount) { {{ p.contract_fees_amount | dwMoney: c.currency_code }} }</td>
                      </tr>
                    } @empty { <tr><td class="dw-faint">{{ t('contracts.noPickupYet') }}</td></tr> }
                  </tbody>
                </table></div>
                <p class="dw-faint hint">{{ t('contracts.pickupsHint') }}</p>
              </div>
            </section>
          </div>
        }
      </dw-state>
    </ng-container>
  `,
  styles: `
    .lifecycle { margin-block-end: 18px; }
    .banner { margin: 0 0 var(--dw-4); padding: 10px 12px; border-radius: var(--dw-radius); background: var(--dw-surface-2); font-size: 13.5px; }
    .banner.bad { background: var(--dw-danger-soft, var(--dw-surface-2)); color: var(--dw-danger); }
    .dw-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1px; background: var(--dw-line-soft); border: 1px solid var(--dw-line-soft); border-radius: var(--dw-radius); overflow: hidden; margin-block-end: var(--dw-4); }
    .stat { background: var(--dw-surface); padding: 12px 16px; } .label { font-size: 12px; color: var(--dw-ink-2); } .value { font-size: 20px; font-weight: 650; } .value.bad { color: var(--dw-danger); } .hint { font-size: 12px; color: var(--dw-ink-3); }
    /* the panels of the page's two columns may shrink below their tables: a long list scrolls inside its panel */
    .dw-panel { min-width: 0; } .scroll-x { overflow-x: auto; }
    table.plain { width: 100%; border-collapse: collapse; font-size: 13px; } .plain td { padding: 6px 8px; border-block-end: 1px solid var(--dw-line-soft); } .num { text-align: end; white-space: nowrap; }
    .notes { white-space: pre-line; } .hint { margin: var(--dw-2) 0 0; }
  `,
})
export class ContractPage implements OnInit {
  protected contractSteps(t: (key: string) => string): { key: string; label: string }[] {
    return [{ key: 'draft', label: t('status.contract.draft') }, { key: 'active', label: t('status.contract.active') }, { key: 'ended', label: t('contracts.ended') }];
  }

  private readonly api = inject(Api);
  private readonly dialog = inject(Dialogs);
  private readonly confirm = inject(Confirm);
  private readonly toast = inject(Toast);
  private readonly transloco = inject(TranslocoService);
  protected readonly session = inject(Session);

  readonly ulid = input.required<string>();

  protected readonly provider = this.session.audience() === 'provider';
  protected readonly canManage = !this.provider && this.session.can('contracts.manage');
  protected readonly contract = signal<Contract | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<unknown>(null);
  protected readonly busy = signal(false);
  protected readonly late = computed(() => this.contract()?.progress.late ?? false);

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.get<Contract>(`contracts/${this.ulid()}`).subscribe({
      next: (contract) => { this.contract.set(contract); this.loading.set(false); },
      error: (error: unknown) => { this.error.set(error); this.loading.set(false); },
    });
  }

  protected edit(): void {
    const contract = this.contract();
    if (contract) {
      this.dialog.open<ContractDialog, { contract?: Contract }, Contract>(ContractDialog, { data: { contract } }).afterClosed().subscribe((saved) => saved && this.contract.set(saved));
    }
  }

  protected async activate(): Promise<void> {
    const ok = await this.confirm.ask({ title: this.transloco.translate('contracts.activateTitle'), message: this.transloco.translate('contracts.activateMessage'), confirmLabel: this.transloco.translate('contracts.activate') });
    if (ok) {
      await this.post('activate', {}, 'contracts.activated');
    }
  }

  protected async terminate(): Promise<void> {
    const reason = await this.confirm.ask({ title: this.transloco.translate('contracts.terminateTitle'), reasonLabel: this.transloco.translate('contracts.terminateReason'), danger: true,
      confirmLabel: this.transloco.translate('contracts.terminate') });
    if (reason !== null) {
      await this.post('terminate', { reason: reason === true ? null : reason }, 'contracts.terminated');
    }
  }

  private async post(action: string, body: unknown, success: string): Promise<void> {
    this.busy.set(true);
    try {
      this.contract.set(await firstValueFrom(this.api.post<Contract>(`contracts/${this.ulid()}/${action}`, body)));
      this.toast.success(success);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}
