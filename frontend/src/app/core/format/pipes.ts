import { Pipe, PipeTransform, inject } from '@angular/core';

import { Localized } from '../api/models';
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
