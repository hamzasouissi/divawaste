import { provideZonelessChangeDetection } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';

import { Locale } from '../i18n/i18n';
import { KnownWeightPipe, LotMeasurePipe, QuantityPipe } from './pipes';

describe('measures: a weight nobody measured is said, never shown as zero', () => {
  const create = <T>(pipe: new () => T): T => TestBed.runInInjectionContext(() => new pipe());
  const words: Record<string, string> = { 'units.kg': 'kg', 'units.piece': 'pièces', 'units.piece_one': 'pièce', 'units.l': 'l', 'lots.toWeigh': 'À peser' };

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideZonelessChangeDetection(),
        { provide: Locale, useValue: { intl: 'fr-TN' } },
        { provide: TranslocoService, useValue: { translate: (key: string, params?: { count?: number }) => (key === 'lots.unweighedCount' ? `${params?.count} lot(s) non pesé(s)` : (words[key] ?? key)) } },
      ],
    });
  });

  it('shows a quantity in its own unit, singular for one piece, signed when asked', () => {
    const quantity = create(QuantityPipe);

    expect(quantity.transform('240.000', 'piece')).toMatch(/^240\spièces$/);
    expect(quantity.transform('1', 'piece')).toMatch(/^1\spièce$/);
    expect(quantity.transform('12.5', 'l')).toMatch(/^12,5\sl$/);
    expect(quantity.transform('8', 'piece', true)).toMatch(/^\+8\spièces$/);
    expect(quantity.transform(null, 'piece')).toBe('—');
  });

  it('describes a lot by what is known of it', () => {
    const lot = create(LotMeasurePipe);

    expect(lot.transform({ net_weight_kg: '118.000', weight_basis: 'measured' })).toMatch(/^118\skg$/);
    // Not weighed yet: it is to be weighed, it does not weigh 0 kg.
    expect(lot.transform({ net_weight_kg: null, weight_basis: 'unknown' })).toBe('À peser');
    // A typed zero is a weight someone entered.
    expect(lot.transform({ net_weight_kg: '0.000', weight_basis: 'measured' })).toMatch(/^0\skg$/);
    // An estimate and a computed weight are marked as approximations.
    expect(lot.transform({ net_weight_kg: '40', weight_basis: 'estimated' })).toMatch(/^≈\s40\skg$/);
    // Counted waste: its count, and its weight beside it only when there is one.
    expect(lot.transform({ net_weight_kg: null, weight_basis: 'unknown', quantity: '240', unit: 'piece' })).toMatch(/^240\spièces$/);
    expect(lot.transform({ net_weight_kg: '84', weight_basis: 'measured', quantity: '240', unit: 'piece' })).toMatch(/^240\spièces · 84\skg$/);
    expect(lot.transform({ net_weight_kg: '84', weight_basis: 'computed', quantity: '240', unit: 'piece' })).toMatch(/^240\spièces · ≈\s84\skg$/);
  });

  it('gives a total as the known weight plus the lots that have none', () => {
    const total = create(KnownWeightPipe);

    expect(total.transform('950', 0)).toMatch(/^950\skg$/);
    expect(total.transform('950', 2)).toMatch(/^950\skg \+ 2 lot\(s\) non pesé\(s\)$/);
    // Nothing weighed at all: no "0 kg" in front of the count.
    expect(total.transform('0.000', 3)).toBe('3 lot(s) non pesé(s)');
    expect(total.transform(null, 1)).toBe('1 lot(s) non pesé(s)');
    expect(total.transform(null, undefined)).toBe('—');
  });
});
