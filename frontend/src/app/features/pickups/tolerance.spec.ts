import { PickupLine, ToleranceDecision } from '../../core/api/models';
import { dimensionOf } from '../catalog/waste-types';
import { precision, ruleProblem } from '../catalog/tolerances';
import { counted, measure, nextStep, reason, repeated } from './tolerance';

const line = (over: Partial<PickupLine>): PickupLine => ({
  lot: 'L1', lot_number_snapshot: 'L-2026-0001', qr_tag_value_snapshot: null, waste_type_label_snapshot: 'Cônes', regulatory_code_snapshot: null, is_hazardous: false,
  line_status: 'received', declared_weight_kg: '0.000', departure_weight_kg: '0.000', received_weight_kg: null, weight_variance_pct: null, variance_flagged: false,
  treatment_channel: null, pricing_mode: null, unit_price: null, net_amount: null, ...over,
});
const decision = (over: Partial<ToleranceDecision>): ToleranceDecision => ({
  source: 'type', rule: 'R1', warning_pct: '5.00', blocking_pct: '10.00', direction: 'both', basis: 'operational', reference: null, channel: null,
  unit: 'piece', reference_quantity: '100.000', measured_quantity: '108.000', difference: '8.000', pct: '8.00', pct_abs: '8.00', ...over,
});

describe('tolerance: what a line is measured in and what its outcome asks for', () => {
  it('reads a weighed line in kilograms and a counted one in its own unit, at each stage', () => {
    const weighed = line({ declared_weight_kg: '100.000', departure_weight_kg: '98.000', received_weight_kg: '97.500' });
    expect(counted(weighed)).toBe(false);
    expect([measure(weighed, 'declared'), measure(weighed, 'departure'), measure(weighed, 'received')])
      .toEqual([{ value: '100.000', unit: 'kg' }, { value: '98.000', unit: 'kg' }, { value: '97.500', unit: 'kg' }]);

    // Pieces are never read as a weight, even when a weight sits beside them.
    const pieces = line({ measure_dimension: 'count', unit: 'piece', declared_quantity: '100.000', departure_quantity: '100.000', received_quantity: '108.000', received_weight_kg: '36.000' });
    expect(counted(pieces)).toBe(true);
    expect(measure(pieces, 'received')).toEqual({ value: '108.000', unit: 'piece' });
    // Nothing counted on departure stays unknown: it is not replaced by the weight.
    expect(measure(line({ measure_dimension: 'count', unit: 'piece' }), 'departure')).toEqual({ value: null, unit: 'piece' });
  });

  it('gives the reason of an outcome with the limit that was crossed', () => {
    expect(reason(decision({}), 'warning')).toEqual({ key: 'tolerance.reasons.warning', limit: '5.00' });
    expect(reason(decision({}), 'blocking')).toEqual({ key: 'tolerance.reasons.blocking', limit: '10.00' });
    expect(reason(decision({ pct: null }), 'no_reference')).toEqual({ key: 'tolerance.reasons.no_reference', limit: null });
    expect(reason(decision({ difference: '3.000' }), 'within')).toEqual({ key: 'tolerance.reasons.within', limit: '5.00' });
    // A shortage under a rule that only watches excesses is accepted whatever its size — and said to be for that reason.
    expect(reason(decision({ direction: 'excess', difference: '-20.000' }), 'within').key).toBe('tolerance.reasons.ignored_side');
    expect(reason(decision({ direction: 'shortage', difference: '20.000' }), 'within').key).toBe('tolerance.reasons.ignored_side');
    // What left was only an estimate: whatever the gap, the tolerance is not said to be met.
    expect(reason(decision({ reference_basis: 'estimated', difference: '-1.000' }), 'no_reference')).toEqual({ key: 'tolerance.reasons.estimated_reference', limit: null });
    expect(reason(decision({ reference_basis: 'measured', difference: '3.000' }), 'within').key).toBe('tolerance.reasons.within');
    // A rule that only blocks states its blocking limit.
    expect(reason(decision({ warning_pct: null, difference: '3.000' }), 'within').limit).toBe('10.00');
  });

  it('says who has to do what before the pickup can be closed', () => {
    expect(nextStep('within', false, true)).toBe('none');
    expect(nextStep(null, false, true)).toBe('none');
    expect(nextStep('warning', false, false)).toBe('explain');
    expect(nextStep('no_reference', false, false)).toBe('explain');
    // Above the blocking limit an explanation is not enough: it takes someone entitled to accept it.
    expect(nextStep('blocking', false, true)).toBe('authorize');
    expect(nextStep('blocking', false, false)).toBe('ask_authorized');
    expect(nextStep('blocking', true, false)).toBe('none');
  });

  it('orders rules from the particular to the general and refuses limits that cannot work', () => {
    const type = { ulid: 'T', code: 'C', name: 'Cônes', unit: 'piece' };
    expect([precision({ waste_type: type, treatment_channel: 'reuse' }), precision({ waste_type: type, treatment_channel: null }),
      precision({ waste_type: null, treatment_channel: 'reuse' }), precision({ waste_type: null, treatment_channel: null })]).toEqual(['type_channel', 'type', 'channel', 'company']);

    expect(ruleProblem('', '')).toBe('none');
    expect(ruleProblem('5', '3')).toBe('order');
    expect(ruleProblem('5', '10')).toBeNull();
    expect(ruleProblem('', '10')).toBeNull();
    expect(ruleProblem('2,5', '2,5')).toBeNull();
  });

  it('marks a waste type whose receptions keep falling outside tolerance', () => {
    expect(repeated({ warning: 1, blocking: 0 })).toBe(false);
    expect(repeated({ warning: 1, blocking: 1 })).toBe(true);
    expect(repeated({ warning: 0, blocking: 2 })).toBe(true);
  });

  it('derives what a waste type is declared in from its unit', () => {
    expect([dimensionOf('kg'), dimensionOf('t'), dimensionOf('piece'), dimensionOf('l'), dimensionOf('m3'), dimensionOf(null)])
      .toEqual(['mass', 'mass', 'count', 'volume', 'volume', 'mass']);
  });
});
