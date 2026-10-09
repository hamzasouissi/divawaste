import { LimitResult } from '../core/api/models';
import { failingLimits, worstOutcome } from './limits';

const limit = (over: Partial<LimitResult>): LimitResult => ({ key: 'weight', unit: 'kg', pct: 50, outcome: 'respected', blocking: true, missing_count: 0, missing_lots: [], ...over });

describe('limits: each one answered on its own', () => {
  it('names the limits that refuse an operation, whatever the percentage of the others', () => {
    // 90 % of the weight and 103 % of the bags: the bags refuse, the weight does not.
    const weight = limit({ pct: 90 });
    const bags = limit({ key: 'packages', unit: 'package', pct: 103.3, outcome: 'exceeded' });
    expect(failingLimits([weight, bags]).map((item) => item.key)).toEqual(['packages']);

    // A limit that cannot be verified refuses too, even at 20 %: unknown is not zero.
    const unknown = limit({ pct: 20, outcome: 'unverifiable', missing_count: 1, missing_lots: ['L-2026-000012'] });
    expect(failingLimits([unknown, bags]).map((item) => item.key)).toEqual(['weight', 'packages']);

    // A warning refuses nothing, and neither does a limit that only alerts (a zone, a stock limit).
    expect(failingLimits([limit({ pct: 95, outcome: 'warning' })])).toEqual([]);
    expect(failingLimits([limit({ key: 'load', outcome: 'exceeded', blocking: false })])).toEqual([]);
  });

  it('gives the most serious answer, not the highest percentage', () => {
    expect(worstOutcome([])).toBeNull();
    expect(worstOutcome([limit({ pct: 99, outcome: 'warning' }), limit({ pct: 10, outcome: 'unverifiable' })])).toBe('unverifiable');
    expect(worstOutcome([limit({ pct: 10, outcome: 'unverifiable' }), limit({ pct: 101, outcome: 'exceeded' })])).toBe('exceeded');
    expect(worstOutcome([limit({}), limit({ key: 'lots', unit: 'lot' })])).toBe('respected');
  });
});
