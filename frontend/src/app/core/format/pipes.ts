import { Pipe, PipeTransform, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { TranslocoService } from '@jsverse/transloco';

import { Localized } from '../api/models';
import { ReferenceData } from '../data/reference';
import { Locale } from '../i18n/i18n';

type Numeric = string | number | null | undefined;

const EMPTY = '—';
// Non-breaking space: a figure and its unit never wrap apart.
const NBSP = String.fromCharCode(0xa0);

function toNumber(value: Numeric): number | null {
  if (value === null || value === undefined || value === '') {
    return null;
  }
  const parsed = typeof value === 'number' ? value : Number(value);

  return Number.isFinite(parsed) ? parsed : null;
}

/** `1234.5 | dwKg` → "1 234,5 kg"; `| dwKg:'auto'` switches to tonnes from one tonne up. */
@Pipe({ name: 'dwKg' })
export class KgPipe implements PipeTransform {
  private readonly locale = inject(Locale);

  transform(value: Numeric, unit: 'kg' | 't' | 'auto' = 'kg'): string {
    const kg = toNumber(value);
    if (kg === null) {
      return EMPTY;
    }
    const tonnes = unit === 't' || (unit === 'auto' && Math.abs(kg) >= 1000);
    const formatted = new Intl.NumberFormat(this.locale.intl, { maximumFractionDigits: tonnes ? 2 : 1 }).format(tonnes ? kg / 1000 : kg);

    return `${formatted}${NBSP}${tonnes ? 't' : 'kg'}`;
  }
}

/**
 * A quantity in the unit it was counted in: `108 | dwQty: 'piece'` → "108 pièces", `12.5 | dwQty: 'l'` → "12,5 l".
 * `signed` puts a + before a positive gap.
 */
@Pipe({ name: 'dwQty', pure: false })
export class QuantityPipe implements PipeTransform {
  private readonly locale = inject(Locale);
  private readonly transloco = inject(TranslocoService);

  transform(value: Numeric, unit: string | null | undefined, signed = false): string {
    const parsed = toNumber(value);
    if (parsed === null) {
      return EMPTY;
    }
    const formatted = new Intl.NumberFormat(this.locale.intl, { maximumFractionDigits: 3, signDisplay: signed ? 'exceptZero' : 'auto' }).format(parsed);

    // French counts 0 and 1 in the singular; the other units are symbols and do not change.
    const word = unit === 'piece' && Math.abs(parsed) < 2 ? 'piece_one' : (unit ?? 'kg');

    return `${formatted}${NBSP}${this.transloco.translate('units.' + word)}`;
  }
}

/**
 * How much a waste lot holds, in the unit it was declared in: "118 kg" for weighed waste, "240 pièces" for waste
 * counted in pieces or litres — with its weight beside it only when one is known (a computed weight is marked ≈).
 */
@Pipe({ name: 'dwLotMeasure', pure: false })
export class LotMeasurePipe implements PipeTransform {
  private readonly quantity = new QuantityPipe();
  private readonly kg = new KgPipe();

  transform(lot: { net_weight_kg: Numeric; weight_basis?: string | null; quantity?: Numeric; unit?: string | null } | null | undefined): string {
    if (!lot) {
      return EMPTY;
    }
    if (toNumber(lot.quantity) === null || !['piece', 'l', 'm3'].includes(lot.unit ?? '')) {
      return this.kg.transform(lot.net_weight_kg);
    }
    const counted = this.quantity.transform(lot.quantity, lot.unit);

    return lot.weight_basis === 'unknown' || toNumber(lot.net_weight_kg) === 0 ? counted : `${counted} · ${lot.weight_basis === 'computed' ? '≈' + NBSP : ''}${this.kg.transform(lot.net_weight_kg)}`;
  }
}

@Pipe({ name: 'dwNumber' })
export class NumberPipe implements PipeTransform {
  private readonly locale = inject(Locale);

  transform(value: Numeric, maximumFractionDigits = 0, suffix = ''): string {
    const parsed = toNumber(value);

    return parsed === null ? EMPTY : new Intl.NumberFormat(this.locale.intl, { maximumFractionDigits }).format(parsed) + suffix;
  }
}

/** Money keeps the three decimals of the dinar; the currency comes from the company. */
@Pipe({ name: 'dwMoney' })
export class MoneyPipe implements PipeTransform {
  private readonly locale = inject(Locale);

  transform(value: Numeric, currency: string | null | undefined): string {
    const amount = toNumber(value);
    if (amount === null) {
      return EMPTY;
    }

    return new Intl.NumberFormat(this.locale.intl, { style: 'currency', currency: currency ?? 'TND' }).format(amount);
  }
}

/** Dates arrive in UTC and are shown in the user's time zone. */
@Pipe({ name: 'dwDate' })
export class DatePipe implements PipeTransform {
  private readonly locale = inject(Locale);

  transform(value: string | null | undefined, style: 'date' | 'datetime' | 'month' | 'time' = 'date'): string {
    if (!value) {
      return EMPTY;
    }
    // A bare date (YYYY-MM-DD) is a calendar day, not an instant: keep it on that day in every time zone.
    const date = /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T12:00:00`) : new Date(value.includes('T') ? value : `${value.replace(' ', 'T')}Z`);
    if (Number.isNaN(date.getTime())) {
      return EMPTY;
    }
    const options: Intl.DateTimeFormatOptions =
      style === 'datetime'
        ? { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }
        : style === 'month'
          ? { month: 'short', year: 'numeric' }
          : style === 'time'
            ? { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }
            : { dateStyle: 'medium' };

    return new Intl.DateTimeFormat(this.locale.intl, options).format(date);
  }
}

/** Reference labels sent by the API in every language. */
@Pipe({ name: 'dwLocalized', pure: false })
export class LocalizedPipe implements PipeTransform {
  private readonly locale = inject(Locale);

  transform(value: Localized | null | undefined, fallback = EMPTY): string {
    return this.locale.pick(value, fallback);
  }
}

export const FORMAT_PIPES = [KgPipe, NumberPipe, MoneyPipe, DatePipe, LocalizedPipe] as const;

const humanize = (code: string): string => {
  const text = code.replace(/[._]+/g, ' ').trim();

  return text.charAt(0).toUpperCase() + text.slice(1);
};

/**
 * Name of a reference value sent by the API as a code (packaging, colour family, material, unit):
 * `{{ lot.packaging_type | dwCode: 'packaging' }}`. Falls back to a readable form of the code, never to the raw code.
 */
@Pipe({ name: 'dwCode', pure: false })
export class CodePipe implements PipeTransform {
  private readonly locale = inject(Locale);
  private readonly vocabulary = toSignal(inject(ReferenceData).vocabulary());

  transform(code: string | null | undefined, kind: 'packaging' | 'color' | 'material' | 'unit' | 'family' | 'zone'): string {
    if (!code) {
      return '—';
    }
    const vocabulary = this.vocabulary();
    const list = { packaging: vocabulary?.packaging_types, color: vocabulary?.color_families, material: vocabulary?.materials, unit: vocabulary?.units, family: vocabulary?.waste_families, zone: vocabulary?.zone_types }[kind];
    const item = list?.find((entry) => entry.code === code);
    if (kind === 'unit' && item && 'symbol' in item) {
      return String(item.symbol);
    }

    return item ? this.locale.pick(item.name as Localized, humanize(code)) : humanize(code);
  }
}

/**
 * Word for a technical term (origin of an action, audited object, permission, module…) taken from the
 * translation group of that name: `{{ entry.source | dwTerm: 'terms.sources' }}`. Unknown terms are shown in a
 * readable form rather than as an identifier.
 */
@Pipe({ name: 'dwTerm', pure: false })
export class TermPipe implements PipeTransform {
  private readonly transloco = inject(TranslocoService);

  transform(code: string | null | undefined, group: string): string {
    if (!code) {
      return '—';
    }
    const words = this.transloco.translateObject<Record<string, string> | string>(group);

    return (typeof words === 'object' && words !== null ? words[code] : undefined) ?? humanize(code);
  }
}

/** Country name from its ISO code, in the language of the interface: `TN` → "Tunisie". */
@Pipe({ name: 'dwCountry' })
export class CountryPipe implements PipeTransform {
  private readonly locale = inject(Locale);

  transform(code: string | null | undefined): string {
    if (!code) {
      return '—';
    }
    try {
      return new Intl.DisplayNames([this.locale.intl], { type: 'region' }).of(code.toUpperCase()) ?? code;
    } catch {
      return code;
    }
  }
}
