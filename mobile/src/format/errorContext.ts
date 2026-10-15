/** Mirrors ErrorEvent::contexts() - keys to the labels Filament shows. */
const CONTEXT_LABELS: Record<string, string> = {
  csv_upload: 'CSV upload',
  csv_row: 'CSV row',
  search_run: 'Search run',
  image_download: 'Image download',
  wikimedia_block: 'Wikimedia block',
};

/**
 * A context's label. One the app does not know yet shows as its raw key, as
 * the panel does, rather than disappearing.
 */
export function contextLabel(context: string): string {
  return CONTEXT_LABELS[context] ?? context;
}
