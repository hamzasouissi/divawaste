import { provideZonelessChangeDetection } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { of } from 'rxjs';

import { LimitResult } from '../api/models';
import { ReferenceData } from '../data/reference';
import { Locale } from '../i18n/i18n';
import { LimitText } from './limit-text';

const limit = (over: Partial<LimitResult>): LimitResult => ({ key: 'weight', unit: 'kg', pct: 50, outcome: 'respected', blocking: true, missing_count: 0, missing_lots: [], ...over });

describe('limit text: a refusal says which limit, with the figures the API answered with', () => {
  const words: Record<string, string> = {
    'limits.name.weight': 'Poids', 'limits.name.packaging': 'Colis', 'limits.name.packages': 'Colis (quantité commune)', 'common.or': 'ou',
    'limits.problem.exceeded': 'limite de {{max}} dépassée de {{by}}', 'limits.problem.sharedExceeded': 'quantité commune ({{lines}}) dépassée de {{pct}} %',
    'limits.problem.weightUnknown': 'poids inconnu pour {{lots}} : pesez', 'limits.problem.countUnknown': 'nombre de colis ({{packaging}}) inconnu pour {{lots}}',
    'limits.problem.unlisted': '{{lots}} en vrac ou non nommé', 'limits.problem.alsoMissing': '(sans compter {{lots}})',
    'errors.authorizationRefused': 'Refusé par l’autorisation {{reference}} ({{wasteType}}) : {{problems}}.', 'dashboard.lots': '{{count}} lot(s)',
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

  const bags = limit({ key: 'packages:bag', unit: 'package', packaging_type: 'bag', used: 310, max: 300, excess: 10, pct: 103.3, outcome: 'exceeded' });

  it('names a limit by what it limits', () => {
    expect([text.name(limit({})), text.name(bags), text.name(limit({ key: 'packages', unit: 'package', lines: [] }))]).toEqual(['Poids', 'Colis (Sac)', 'Colis (quantité commune)']);
  });

  it('says the exceeded limit and by how much, in its own unit', () => {
    expect(text.problem(bags)).toBe('limite de 300 × Sac dépassée de 10 × Sac');
    expect(text.problem(limit({ used: '520.000', max: '500.000', excess: '20.000', outcome: 'exceeded' }))).toMatch(/^limite de 500\skg dépassée de 20\skg$/);
    // A limit that is respected has nothing to say.
    expect(text.problem(limit({ used: '450.000', max: '500.000' }))).toBe('');
  });

  it('names the lots a limit could not verify, and what to do', () => {
    expect(text.problem(limit({ outcome: 'unverifiable', missing_count: 1, missing_lots: ['L-2026-000082'] }))).toBe('poids inconnu pour L-2026-000082 : pesez');
    expect(text.problem(limit({ key: 'packages:bale', unit: 'package', packaging_type: 'bale', outcome: 'unverifiable', missing_count: 1, missing_lots: ['L-1'] }))).toBe('nombre de colis (Balle) inconnu pour L-1');
    expect(text.problem(limit({ key: 'unlisted', unit: 'package', pct: null, outcome: 'unverifiable', missing_count: 2, missing_lots: ['L-1', 'L-2'] }))).toBe('L-1, L-2 en vrac ou non nommé');
  });

  it('builds the refusal from every limit that failed, and only those', () => {
    const refusal = text.refusal({ reference: 'DOUANE-2026-0187', waste_type: 'Rebuts', reason: 'exceeded', constraints: [
      limit({ used: '450.000', max: '500.000', pct: 90, outcome: 'unverifiable', missing_count: 1, missing_lots: ['L-2026-000082'] }), bags,
      limit({ key: 'packages:bale', unit: 'package', packaging_type: 'bale', used: 10, max: 40, pct: 25 }),
    ] });
    expect(refusal).toBe('Refusé par l’autorisation DOUANE-2026-0187 (Rebuts) : poids inconnu pour L-2026-000082 : pesez ; limite de 300 × Sac dépassée de 10 × Sac.');
    // Nothing to explain with: the caller falls back to the message of the error code.
    expect(text.refusal(null)).toBeNull();
    expect(text.refusal({ reference: 'X', constraints: [] })).toBeNull();
  });
});
