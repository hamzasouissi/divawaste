import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { TranslocoDirective } from '@jsverse/transloco';
import { ButtonDirective } from 'primeng/button';
import { InputText } from 'primeng/inputtext';
import { Textarea } from 'primeng/textarea';
import { catchError, firstValueFrom, map, of } from 'rxjs';

import { Api } from '../../core/api/api';
import { Lot } from '../../core/api/models';
import { Session } from '../../core/auth/session';
import { PriceComponent } from '../../ui/price';
import { IconComponent } from '../../ui/icon';
import { Toast } from '../../core/feedback/toast';
import { KgPipe, LotMeasurePipe, MoneyPipe } from '../../core/format/pipes';
import { CheckboxComponent } from '../../ui/checkbox';
import { DateComponent } from '../../ui/date';
import { DIALOG_PARTS, DialogRef, dialogData } from '../../ui/dialog';
import { FIELD } from '../../ui/field';
import { SELECT } from '../../ui/select';
import { Direction, PricingMode, Proposal, SellerListing, Terms } from './marketplace-models';

const PRICE = [Validators.required, Validators.pattern(/^\d+([.,]\d{1,3})?$/)];
const decimal = (value: string): string => value.replace(',', '.');

/** The amount of a price on a weight, signed for the seller (positive = it receives); null without a weight. */
export function amountOf(terms: Terms, weightKg: string | null): number | null {
  const price = Number(decimal(terms.unit_price));
  if (Number.isNaN(price)) {
    return null;
  }
  const weight = weightKg === null ? null : Number(weightKg);
  const amount = terms.pricing_mode === 'flat' ? price : weight === null ? null : terms.pricing_mode === 'per_tonne' ? (weight * price) / 1000 : weight * price;

  return amount === null ? null : terms.direction === 'cost' ? -amount : amount;
}

export interface CreateListingData {
  site?: string;
  /** Lots ticked from the start (the lot the seller came from). */
  lots?: string[];
  wasteType?: string | null;
  /** Editing an existing listing. */
  listing?: SellerListing;
}

/**
 * A listing of the bourse: lots of one site and one waste type, in stock and free, described for the buyers, with an
 * asking price if the seller wants one. Saved as a draft; publishing is a separate step. The seller stays anonymous
 * to the buyers until a deal is made, unless it chooses to show its name.
 */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, ButtonDirective, InputText, Textarea, CheckboxComponent, DateComponent, LotMeasurePipe, ...FIELD, ...SELECT, ...DIALOG_PARTS, PriceComponent, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ editing ? t('marketplace.editListing', { number: editing.listing_number }) : t('marketplace.newListing') }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <div class="dw-form">
            @if (!editing && !fromLot) {
              <dw-field [label]="t('fields.site')">
                <dw-select formControlName="site" required (valueChange)="loadLots()">
                  @for (site of sites; track site.ulid) { <dw-option [value]="site.ulid" [label]="site.name" /> }
                </dw-select>
              </dw-field>
              <dw-field [label]="t('fields.wasteType')">
                <dw-select formControlName="waste_type" required (valueChange)="picked.set([])">
                  @for (type of typesInStock(); track type.ulid) { <dw-option [value]="type.ulid" [label]="type.name" /> }
                </dw-select>
              </dw-field>
            }
            @if (!fromLot && (!editing || editing.status === 'draft')) {
              <fieldset class="dw-span-2 lots">
                <legend class="dw-label">{{ t('marketplace.lotsToSell') }}</legend>
                @for (lot of candidates(); track lot.ulid) {
                  <dw-checkbox [checked]="picked().includes(lot.ulid)" (checkedChange)="toggle(lot.ulid, $event)">
                    <span class="dw-mono">{{ lot.lot_number }}</span> · {{ lot | dwLotMeasure }}
                    @if (lot.composition_label) { <span class="dw-faint"> · {{ lot.composition_label }}</span> }
                  </dw-checkbox>
                } @empty { <p class="dw-faint">{{ t('marketplace.noLotInStock') }}</p> }
                @if (picked().length > 0) { <p class="dw-muted total">{{ t('marketplace.pickedLots', { count: picked().length }) }}</p> }
              </fieldset>
            }
            <dw-field class="dw-span-2" [label]="t('marketplace.listingTitle')">
              <input pInputText formControlName="title" maxlength="191" [placeholder]="t('marketplace.titlePlaceholder')" />
            </dw-field>
            <dw-field class="dw-span-2" [label]="t('marketplace.description')">
              <textarea pTextarea formControlName="description" rows="3" maxlength="5000" [placeholder]="t('marketplace.descriptionPlaceholder')"></textarea>
            </dw-field>

            <h3 class="dw-h3 dw-form-section">{{ t('marketplace.askingPrice') }}</h3>
            <dw-checkbox class="dw-span-2" formControlName="has_asking">{{ t('marketplace.setAskingPrice') }}</dw-checkbox>
            @if (form.controls.has_asking.value) {
              <dw-price-input class="dw-span-2" [direction]="form.controls.direction" [mode]="form.controls.pricing_mode" [amount]="form.controls.unit_price" [currency]="currency" [required]="true" />
            }

            <h3 class="dw-h3 dw-form-section">{{ t('marketplace.conditions') }}</h3>
            <dw-field [label]="t('marketplace.availableFrom')"><dw-date formControlName="available_from" [min]="today" /></dw-field>
            <dw-field [label]="t('marketplace.closesAt')">
              <dw-date formControlName="closes_at" [min]="today" />
              <small dwHint>{{ t('marketplace.closesAtHint') }}</small>
            </dw-field>
            <dw-checkbox class="dw-span-2" formControlName="show_seller">{{ t('marketplace.showSeller') }}</dw-checkbox>
            <p class="dw-span-2 dw-faint anonymous">{{ t(form.controls.show_seller.value ? 'marketplace.shownHint' : 'marketplace.anonymousHint') }}</p>
          </div>
        </div>
        <div class="dw-dialog-foot">
          <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
          @if (!editing || editing.status === 'draft') {
            <button pButton type="submit" severity="secondary" outlined [disabled]="busy() || picked().length === 0">{{ t(editing ? 'common.save' : 'marketplace.saveDraft') }}</button>
            <button pButton type="button" [disabled]="busy() || picked().length === 0" (click)="submit(true)"><dw-icon>campaign</dw-icon>{{ t('marketplace.publish') }}</button>
          } @else {
            <button pButton type="submit" [disabled]="busy()">{{ t('common.save') }}</button>
          }
        </div>
      </form>
    </ng-container>
  `,
  styles: `
    .dw-dialog-body { inline-size: min(660px, 90vw); }
    .lots { display: grid; gap: 6px; border: 1px solid var(--dw-line-soft); border-radius: var(--dw-radius); padding: var(--dw-3); max-block-size: 260px; overflow: auto; margin: 0; }
    .lots legend { padding-inline: 4px; } .total { margin: 0; font-size: 13px; } .anonymous { margin: 0; font-size: 12.5px; }
  `,
})
export class CreateListingDialog {
  protected readonly data = dialogData<CreateListingData | undefined>() ?? {};
  protected readonly editing = this.data.listing ?? null;
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);
  protected readonly session = inject(Session);

  protected readonly sites = this.session.sitesFor('marketplace.manage');
  protected readonly modes: PricingMode[] = ['per_tonne', 'per_kg', 'flat'];
  protected readonly currency = this.editing?.currency_code ?? this.session.company()?.currency_code ?? 'TND';
  protected readonly today = new Date().toISOString().slice(0, 10);
  protected readonly busy = signal(false);
  protected readonly picked = signal<string[]>(this.data.lots ?? []);
  private readonly stock = signal<Lot[]>([]);

  protected readonly form = inject(FormBuilder).nonNullable.group({
    site: [this.data.site ?? this.editing?.site.ulid ?? this.sites[0]?.ulid ?? '', Validators.required],
    waste_type: [this.data.wasteType ?? ''],
    title: [this.editing?.title ?? ''],
    description: [this.editing?.description ?? ''],
    has_asking: [!!this.editing?.asking_price],
    pricing_mode: [(this.editing?.asking_price?.pricing_mode ?? 'per_tonne') as PricingMode],
    unit_price: [this.editing?.asking_price ? String(Number(this.editing.asking_price.unit_price)) : '', PRICE],
    direction: [(this.editing?.asking_price?.direction ?? 'revenue') as Direction],
    available_from: [this.editing?.available_from ?? ''],
    closes_at: [this.editing?.closes_at?.slice(0, 10) ?? ''],
    show_seller: [this.editing?.show_seller ?? false],
  });
  private readonly chosen = toSignal(this.form.valueChanges, { initialValue: this.form.getRawValue() });
  /** B-01: opened from one lot — its site, type and lot are known and not asked again. */
  protected readonly fromLot = !this.editing && (this.data.lots?.length ?? 0) > 0 && !!this.data.site;

  /** The waste types that have lots in stock on the site: a listing offers one type. */
  protected readonly typesInStock = computed(() => {
    const seen = new Map<string, { ulid: string; name: string }>();
    this.stock().forEach((lot) => lot.waste_type && seen.set(lot.waste_type.ulid, { ulid: lot.waste_type.ulid, name: lot.waste_type.name }));

    return [...seen.values()];
  });
  /** Lots of the chosen type still in stock; when editing a draft, its own lots are among them. */
  protected readonly candidates = computed(() => this.stock().filter((lot) => lot.waste_type?.ulid === (this.chosen().waste_type || this.typesInStock()[0]?.ulid)));

  constructor() {
    this.loadLots();
  }

  protected loadLots(): void {
    const site = this.form.controls.site.value;
    if (!site) {
      return;
    }
    this.api.list<Lot>('lots', { filter: { site, status: ['created', 'stored'] }, per_page: 100, sort: '-generated_at' })
      .pipe(map((page) => page.data), catchError(() => of([] as Lot[])))
      .subscribe((lots) => {
        this.stock.set(lots);
        // B-01: a title written from the lot, to be corrected if wanted.
        const own = this.fromLot ? lots.find((lot) => this.data.lots?.includes(lot.ulid)) : undefined;
        if (own && !this.form.controls.title.value) {
          const site = this.sites.find((item) => item.ulid === this.form.controls.site.value)?.name ?? '';
          const kg = own.net_weight_kg === null ? '' : ` ${Number(own.net_weight_kg) >= 1000 ? `${(Number(own.net_weight_kg) / 1000).toLocaleString()} t` : `${Number(own.net_weight_kg).toLocaleString()} kg`}`;
          this.form.controls.title.setValue(`${own.waste_type?.name ?? ''}${kg}${site ? ` — ${site}` : ''}`);
        }
        if (!this.form.controls.waste_type.value && lots[0]?.waste_type) {
          this.form.controls.waste_type.setValue(lots[0].waste_type.ulid);
        }
        if (this.editing) {
          // The lots of the draft, by their numbers.
          this.picked.set(lots.filter((lot) => this.editing?.lots?.some((own) => own.lot_number === lot.lot_number)).map((lot) => lot.ulid));
        }
      });
  }

  protected toggle(ulid: string, checked: boolean): void {
    this.picked.update((list) => (checked ? [...new Set([...list, ulid])] : list.filter((item) => item !== ulid)));
  }

  protected async submit(publish = false): Promise<void> {
    const value = this.form.getRawValue();
    if (value.has_asking && this.form.controls.unit_price.invalid) {
      this.form.controls.unit_price.markAsTouched();

      return;
    }
    const body = {
      title: value.title.trim() || null, description: value.description.trim() || null, show_seller: value.show_seller,
      asking_price: value.has_asking ? { pricing_mode: value.pricing_mode, unit_price: decimal(value.unit_price), direction: value.direction } : null,
      available_from: value.available_from || null, closes_at: value.closes_at ? `${value.closes_at} 23:59:59` : null,
      ...(!this.editing || this.editing.status === 'draft' ? { lots: this.picked() } : {}),
    };
    this.busy.set(true);
    try {
      const listing = await firstValueFrom(this.editing
        ? this.api.put<SellerListing>(`marketplace/listings/${this.editing.ulid}`, body)
        : this.api.post<SellerListing>('marketplace/listings', { ...body, site: value.site }));
      if (publish) {
        await firstValueFrom(this.api.post(`marketplace/listings/${listing.ulid}/publish`, {}));
        this.toast.success('marketplace.published');
      } else {
        this.toast.success(this.editing ? 'common.saved' : 'marketplace.draftSaved', { number: listing.listing_number });
      }
      this.ref.close(listing);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}

export interface ProposalData {
  /** Where the proposal goes: a first offer on a listing, or an answer in a negotiation. */
  path: string;
  title: string;
  currency: string;
  /** What the price is applied to: the known weight of the listing, and the lots nobody weighed. */
  weightKg: string | null;
  unweighed: number;
  /** The proposal being answered, or the asking price: its terms are the starting point. */
  from?: Terms | Proposal | null;
  availableFrom?: string | null;
  /** The asked price is taken as it is: only the date and the message are asked. */
  fixed?: boolean;
}

/**
 * A proposal of the bourse — an offer, or the answer to the other side's last proposal: a price per kilogram, per
 * tonne or for the whole, who pays whom, when the lots can be collected, a message, and until when it stands.
 */
@Component({
  imports: [ReactiveFormsModule, TranslocoDirective, ButtonDirective, Textarea, DateComponent, KgPipe, MoneyPipe, ...FIELD, ...SELECT, ...DIALOG_PARTS, PriceComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *transloco="let t">
      <dw-dialog-title>{{ data.title }}</dw-dialog-title>
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="dw-dialog-body">
          <div class="dw-form">
            <!-- B-05: taking the asked price needs no price form, only the date. -->
            @if (data.fixed) {
              <p class="dw-span-2 fixed"><span class="dw-faint">{{ t('marketplace.askedPrice') }}</span><strong>{{ form.controls.unit_price.value | dwMoney: data.currency }} {{ t('price.modes.' + form.controls.pricing_mode.value) }}</strong></p>
            } @else {
              <dw-price-input class="dw-span-2" [direction]="form.controls.direction" [mode]="form.controls.pricing_mode" [amount]="form.controls.unit_price" [currency]="data.currency" [required]="true" [provider]="provider" />
            }
            <p class="dw-span-2 estimate" role="status">
              @if (estimate(); as amount) {
                <!-- The amount is said from the reader's side: what the seller receives is what the taker pays. -->
                {{ t((amount >= 0 ? 'marketplace.estimateRevenue' : 'marketplace.estimateCost') + (provider ? 'Taker' : ''), { amount: (abs(amount) | dwMoney: data.currency), weight: (data.weightKg | dwKg) }) }}
                @if (data.unweighed > 0 && form.controls.pricing_mode.value !== 'flat') { <span class="dw-faint">{{ t('marketplace.unweighedLeftOut', { count: data.unweighed }) }}</span> }
              } @else if (form.controls.pricing_mode.value !== 'flat' && data.weightKg === null) { <span class="dw-faint">{{ t('marketplace.noWeightYet') }}</span> }
            </p>
            <dw-field [label]="t('marketplace.pickupDate')"><dw-date formControlName="pickup_date" [min]="minDate" /></dw-field>
            <dw-field [label]="t('marketplace.validUntil')">
              <dw-date formControlName="expires_at" [min]="today" />
              <small dwHint>{{ t('marketplace.validUntilHint') }}</small>
            </dw-field>
            <dw-field class="dw-span-2" [label]="t('marketplace.message')">
              <textarea pTextarea formControlName="message" rows="3" maxlength="1000"></textarea>
            </dw-field>
          </div>
        </div>
        <div class="dw-dialog-foot">
          <button pButton type="button" severity="secondary" outlined dwDialogClose>{{ t('common.cancel') }}</button>
          <button pButton type="submit" [disabled]="busy()">{{ t('marketplace.send') }}</button>
        </div>
      </form>
    </ng-container>
  `,
  styles: '.dw-dialog-body { inline-size: min(560px, 90vw); } .estimate { margin: 0; font-size: 13.5px; display: grid; gap: 2px; } .fixed { margin: 0; display: flex; justify-content: space-between; gap: 12px; padding: 12px 14px; border-radius: 10px; background: var(--dw-primary-soft); } .fixed strong { font-size: 16px; }',
})
export class ProposalDialog {
  protected readonly data = dialogData<ProposalData>();
  protected readonly provider = inject(Session).audience() === 'provider';
  private readonly api = inject(Api);
  private readonly toast = inject(Toast);
  private readonly ref = inject(DialogRef);

  protected readonly modes: PricingMode[] = ['per_tonne', 'per_kg', 'flat'];
  protected readonly today = new Date().toISOString().slice(0, 10);
  protected readonly minDate = this.data.availableFrom && this.data.availableFrom > this.today ? this.data.availableFrom : this.today;
  protected readonly busy = signal(false);
  protected readonly abs = Math.abs;
  protected readonly form = inject(FormBuilder).nonNullable.group({
    direction: [(this.data.from?.direction ?? 'revenue') as Direction],
    pricing_mode: [(this.data.from?.pricing_mode ?? 'per_tonne') as PricingMode],
    unit_price: [this.data.from ? String(Number(this.data.from.unit_price)) : '', PRICE],
    pickup_date: [(this.data.from && 'pickup_date' in this.data.from ? this.data.from.pickup_date : null) ?? ''],
    expires_at: [''],
    message: [''],
  });
  private readonly value = toSignal(this.form.valueChanges, { initialValue: this.form.getRawValue() });
  protected readonly estimate = computed(() => {
    const value = this.value();
    if (!/^\d+([.,]\d{1,3})?$/.test(value.unit_price ?? '')) {
      return null;
    }

    return amountOf({ pricing_mode: value.pricing_mode ?? 'per_tonne', unit_price: value.unit_price ?? '', direction: value.direction ?? 'revenue' }, this.data.weightKg);
  });

  protected async submit(): Promise<void> {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }
    const value = this.form.getRawValue();
    this.busy.set(true);
    try {
      const result = await firstValueFrom(this.api.post<unknown>(this.data.path, {
        pricing_mode: value.pricing_mode, unit_price: decimal(value.unit_price), direction: value.direction,
        pickup_date: value.pickup_date || null, expires_at: value.expires_at || null, message: value.message.trim() || null,
      }));
      this.toast.success('marketplace.sent');
      this.ref.close(result);
    } catch (error) {
      this.toast.error(error);
    } finally {
      this.busy.set(false);
    }
  }
}
