import { provideZonelessChangeDetection } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { of } from 'rxjs';

import { LimitResult } from '../api/models';
import { ReferenceData } from '../data/reference';
import { Locale } from '../i18n/i18n';
import { LimitText } from './limit-text';
import { notificationParams } from './notification-params';

const limit = (over: Partial<LimitResult>): LimitResult => ({ key: 'stock', unit: 'kg', pct: 50, outcome: 'respected', blocking: false, missing_count: 0, missing_lots: [], ...over });

describe('limits and minimums are said in the unit they are stated in', () => {
  const words: Record<string, string> = {
    'limits.name.stock': 'Limite de stock', 'requirements.name.minimum': 'Minimum', 'requirements.name.packaging': 'Conditionnement demandé',
    'units.t': 't', 'units.l': 'l', 'units.piece': 'pièces', 'units.piece_one': 'pièce',
    'limits.problem.exceeded': 'limite de {{max}} dépassée de {{by}}', 'limits.problem.measureUnknown': 'quantité inconnue pour {{lots}}', 'limits.problem.countUnknown': 'colis ({{packaging}}) inconnus pour {{lots}}',
    'requirements.met': 'au moins {{min}} : {{have}}', 'requirements.notMet': 'au moins {{min}} : {{have}}, il manque {{short}}',
    'requirements.unverifiable': 'au moins {{min}} : {{have}} connus, {{count}} lot(s) non mesurés ({{lots}}), il manquerait {{short}}', 'requirements.alsoMissing': '+ {{count}} non mesuré(s) ({{lots}})',
    'requirements.packagingMet': 'tout est en {{packaging}}', 'requirements.packagingNotMet': '{{packaging}} demandé : {{count}} lot(s) autrement ({{lots}})',
    'errors.providerRequirement': '{{provider}} : {{problems}}', 'errors.providerRequirementOverridable': '{{provider}} : {{problems}} (motif possible)',
  };
  const translate = (key: string, params: Record<string, unknown> = {}): string => (words[key] ?? key).replace(/\{\{(\w+)\}\}/g, (_, name: string) => String(params[name] ?? ''));
  let text: LimitText;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideZonelessChangeDetection(),
        { provide: Locale, useValue: { intl: 'fr-TN', pick: (value: Record<string, string> | null, fallback: string) => value?.['fr'] ?? fallback } },
        { provide: TranslocoService, useValue: { translate } },
        { provide: ReferenceData, useValue: { vocabulary: () => of({ packaging_types: [{ code: 'bag', name: { fr: 'Sac' } }, { code: 'bale', name: { fr: 'Balle' } }] }) } },
      ],
    });
    text = TestBed.inject(LimitText);
  });

  it('writes a quantity with the unit of its limit, never as kilograms by default', () => {
    expect(text.quantity(limit({ unit: 't' }), '0.850')).toMatch(/^0,85\st$/);
    expect(text.quantity(limit({ unit: 'l' }), '550.000')).toMatch(/^550\sl$/);
    expect(text.quantity(limit({ unit: 'piece' }), '240.000')).toMatch(/^240\spièces$/);
    expect(text.quantity(limit({ unit: 'piece' }), '1')).toMatch(/^1\spièce$/);
    expect(text.quantity(limit({ unit: 'package', packaging_type: 'bag' }), 110)).toBe('110 × Sac');
    // A stock limit in bags is still called a stock limit; its unit says the rest.
    expect(text.name(limit({ unit: 'package', packaging_type: 'bag' }))).toBe('Limite de stock');
  });

  it('says what a stock limit in another unit could not count', () => {
    expect(text.problem(limit({ unit: 'piece', outcome: 'unverifiable', missing_count: 1, missing_lots: ['L-1'] }))).toBe('quantité inconnue pour L-1');
    expect(text.problem(limit({ unit: 'package', packaging_type: 'bale', outcome: 'unverifiable', missing_count: 1, missing_lots: ['L-2'] }))).toBe('colis (Balle) inconnus pour L-2');
    expect(text.problem(limit({ unit: 'package', packaging_type: 'bag', used: 110, max: 100, excess: 10, outcome: 'exceeded' }))).toBe('limite de 100 × Sac dépassée de 10 × Sac');
  });

  it('says a minimum with what the collection amounts to and what is missing', () => {
    const minimum = (over: Partial<LimitResult>): LimitResult => limit({ key: 'minimum:1', kind: 'minimum', used: '320.000', min: '500.000', shortfall: '180.000', outcome: 'warning', ...over });
    expect(text.name(minimum({}))).toBe('Minimum');
    expect(text.requirement(minimum({}))).toMatch(/^au moins 500\skg : 320\skg, il manque 180\skg$/);
    expect(text.requirement(minimum({ outcome: 'unverifiable', missing_count: 1, missing_lots: ['L-9'] }))).toMatch(/^au moins 500\skg : 320\skg connus, 1 lot\(s\) non mesurés \(L-9\), il manquerait 180\skg$/);
    // Reached on what is known: the unmeasured lot is still named.
    expect(text.requirement(minimum({ used: '520.000', shortfall: null, outcome: 'respected', missing_count: 1, missing_lots: ['L-9'] }))).toMatch(/^au moins 500\skg : 520\skg \+ 1 non mesuré\(s\) \(L-9\)$/);
    expect(text.requirement(minimum({ unit: 'package', packaging_type: 'bale', used: 12, min: 20, shortfall: 8 }))).toBe('au moins 20 × Balle : 12 × Balle, il manque 8 × Balle');
  });

  it('says a packaging asked for, and refuses with the requirements that block only', () => {
    const packaging = limit({ key: 'packaging:1', kind: 'packaging', unit: 'package', packaging_type: 'bale', outcome: 'exceeded', blocking: true, outside_count: 1, outside_lots: ['L-3'] });
    expect(text.name(packaging)).toBe('Conditionnement demandé');
    expect(text.requirement(packaging)).toBe('Balle demandé : 1 lot(s) autrement (L-3)');
    expect(text.requirement({ ...packaging, outcome: 'respected', outside_count: 0, outside_lots: [] })).toBe('tout est en Balle');

    const warning = limit({ key: 'minimum:2', kind: 'minimum', used: '10.000', min: '50.000', shortfall: '40.000', outcome: 'warning' });
    expect(text.refusal({ provider: 'Recyclo', constraints: [packaging, warning] })).toBe('Recyclo : Balle demandé : 1 lot(s) autrement (L-3)');
    expect(text.refusal({ provider: 'Recyclo', may_override: true, constraints: [packaging] })).toBe('Recyclo : Balle demandé : 1 lot(s) autrement (L-3) (motif possible)');
    expect(text.refusal({ provider: 'Recyclo', constraints: [warning] })).toBeNull();
  });
});

describe('notification parameters are those of the reader’s language', () => {
  it('takes the figures written for the language, and keeps the default otherwise', () => {
    const params = { scope: 'Usine · Hall', detail: '110 sur 100 Sac', detail_en: '110 of 100 Bag', excess: '1 200 kg', excess_en: '1,200 kg', pct: '110' };
    expect(notificationParams(params, 'en')).toEqual(expect.objectContaining({ detail: '110 of 100 Bag', excess: '1,200 kg', scope: 'Usine · Hall', pct: '110' }));
    expect(notificationParams(params, 'fr')).toEqual(expect.objectContaining({ detail: '110 sur 100 Sac', excess: '1 200 kg' }));
    expect(notificationParams(null, 'fr')).toEqual({});
  });

  it('still reads a stock-limit notification sent when limits were only in kilograms', () => {
    expect(notificationParams({ quantity_kg: '700.000', max_quantity_kg: '900.000' }, 'fr')).toEqual(expect.objectContaining({ quantity: '700 kg', max_quantity: '900 kg' }));
    expect(notificationParams({ quantity: '110 Sac', quantity_kg: '1.000' }, 'fr')['quantity']).toBe('110 Sac');
  });
});
