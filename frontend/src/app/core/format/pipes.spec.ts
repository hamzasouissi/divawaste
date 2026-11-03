import { provideZonelessChangeDetection } from '@angular/core';
import { TestBed } from '@angular/core/testing';

import { Locale } from '../i18n/i18n';
import { TranslocoService } from '@jsverse/transloco';

import { DatePipe, KgPipe, LocalizedPipe, MoneyPipe, TermPipe } from './pipes';

describe('format pipes', () => {
  const create = <T>(pipe: new () => T): T => TestBed.runInInjectionContext(() => new pipe());

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideZonelessChangeDetection(), { provide: Locale, useValue: { intl: 'fr-TN', pick: (value: Record<string, string> | null, fallback: string) => value?.['fr'] ?? fallback } }],
    });
  });

  it('formats weights in kilograms, and in tonnes from one tonne up when asked', () => {
    const kg = create(KgPipe);

    expect(kg.transform('42.500')).toMatch(/^42,5\skg$/);
    expect(kg.transform('1528', 'auto')).toMatch(/^1,53\st$/);
    expect(kg.transform('999.9', 'auto')).toMatch(/^999,9\skg$/);
    expect(kg.transform(null)).toBe('—');
    expect(kg.transform('abc')).toBe('—');
  });

  it('finds the label of a code with dots, which the translations hold as nested objects', () => {
    // found in the roles page (2026-11-02): "platform.support.view" showed as "Platform support view"
    TestBed.overrideProvider(TranslocoService, { useValue: { translateObject: () => ({ platform: { support: { view: 'Voir le support' } }, flat: 'Plat' }) } });
    const term = create(TermPipe);

    expect(term.transform('platform.support.view', 'permissions')).toBe('Voir le support');
    expect(term.transform('flat', 'permissions')).toBe('Plat');
  });

  it('keeps zero as a value, not as "nothing"', () => {
    expect(create(KgPipe).transform('0')).toMatch(/^0\skg$/);
  });

  it('formats money with the three decimals of the dinar', () => {
    expect(create(MoneyPipe).transform('358', 'TND')).toMatch(/358,000/);
    expect(create(MoneyPipe).transform(null, 'TND')).toBe('—');
  });

  it('keeps a bare date on its calendar day and uses a 24-hour clock', () => {
    const date = create(DatePipe);

    expect(date.transform('2026-12-31')).toContain('31');
    expect(date.transform('2026-10-04T13:05:00Z', 'time')).toMatch(/^\d{2}:\d{2}$/);
    expect(date.transform(null)).toBe('—');
    expect(date.transform('not a date')).toBe('—');
  });

  it('shows a day given without its hour as that day, without an invented time', () => {
    const date = create(DatePipe);

    expect(date.transform('2026-10-26T00:00:00.000000Z', 'datetime')).toBe('26/10/2026');
    expect(date.transform('2026-10-26T09:30:00Z', 'datetime')).toMatch(/^26\/10\/2026.*\d{2}:\d{2}$/);
  });

  it('picks the label of the active language', () => {
    expect(create(LocalizedPipe).transform({ fr: 'Recyclage', en: 'Recycling' })).toBe('Recyclage');
    expect(create(LocalizedPipe).transform(null, 'client_admin')).toBe('client_admin');
  });
});
