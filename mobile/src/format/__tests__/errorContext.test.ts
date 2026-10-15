import { contextLabel } from '../errorContext';

describe('contextLabel', () => {
  it('labels a known context as the panel does', () => {
    expect(contextLabel('wikimedia_block')).toBe('Wikimedia block');
  });

  it('falls back to the raw key for a context it does not know', () => {
    // As the panel does: a new context shows up under its key rather than
    // disappearing.
    expect(contextLabel('meteor_strike')).toBe('meteor_strike');
  });
});
