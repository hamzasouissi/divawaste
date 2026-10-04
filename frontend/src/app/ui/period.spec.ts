import { presetPeriod } from './period';

describe('presetPeriod', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(2026, 9, 4, 10, 0, 0));
  });

  afterEach(() => vi.useRealTimers());

  it('starts on the first day of the month and ends today', () => {
    expect(presetPeriod('month')).toEqual({ from: '2026-10-01', to: '2026-10-04', site: null });
  });

  it('covers the calendar year and rolling windows', () => {
    expect(presetPeriod('year').from).toBe('2026-01-01');
    expect(presetPeriod('quarter').from).toBe('2026-08-01');
    expect(presetPeriod('12m').from).toBe('2025-11-01');
    expect(presetPeriod('36m').from).toBe('2023-11-01');
  });

  it('keeps the selected site', () => {
    expect(presetPeriod('year', 'NAB').site).toBe('NAB');
  });
});
