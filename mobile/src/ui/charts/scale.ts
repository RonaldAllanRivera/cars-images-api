const MONTHS = [
  'January',
  'February',
  'March',
  'April',
  'May',
  'June',
  'July',
  'August',
  'September',
  'October',
  'November',
  'December',
] as const;

/**
 * Rounds a chart's tallest value up to 1, 2 or 5 times a power of ten, so the
 * axis tops out at a number read at a glance ("20") rather than the raw
 * maximum ("17"). Never below 1: an all-zero window still needs a scale.
 */
export function niceCeiling(value: number): number {
  if (!(value > 0)) return 1;

  const magnitude = 10 ** Math.floor(Math.log10(value));

  // 10 is in the list so a log that lands a hair under a power of ten still
  // finishes on the right answer.
  for (const step of [1, 2, 5, 10]) {
    if (step * magnitude >= value) return step * magnitude;
  }

  return 10 * magnitude;
}

/**
 * Split by hand, never `new Date('2026-09-03')`: that parses as midnight UTC,
 * which is the previous evening anywhere west of Greenwich, and the label
 * would name a day the server did not count.
 */
function parts(day: string): { date: number; month: string } {
  const [, month = 1, date = 1] = day.split('-').map(Number);

  return { date, month: MONTHS[month - 1] ?? '' };
}

/** '2026-09-03' -> '3 September', for screen readers and the callout. */
export function dayLong(day: string): string {
  const { date, month } = parts(day);

  return `${date} ${month}`;
}

/** '2026-09-03' -> '3 Sep', for the axis. */
export function dayShort(day: string): string {
  const { date, month } = parts(day);

  return `${date} ${month.slice(0, 3)}`;
}
