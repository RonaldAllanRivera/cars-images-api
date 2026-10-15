import { dayLong, dayShort, niceCeiling } from '../scale';

describe('niceCeiling', () => {
  it('rounds the tallest value up to 1, 2 or 5 times a power of ten', () => {
    expect([1, 2, 3, 7, 12, 48, 51, 100].map(niceCeiling)).toEqual([
      1, 2, 5, 10, 20, 50, 100, 100,
    ]);
  });

  it('never returns less than 1', () => {
    // An all-zero window still needs a scale, or every bar height divides by
    // zero.
    expect(niceCeiling(0)).toBe(1);
  });
});

describe('day labels', () => {
  it('names the day as written, not as midnight UTC', () => {
    expect(dayLong('2026-09-03')).toBe('3 September');
    expect(dayShort('2026-09-03')).toBe('3 Sep');
  });
});
