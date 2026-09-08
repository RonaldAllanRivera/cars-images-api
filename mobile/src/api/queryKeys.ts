import type { ErrorContext, ErrorSeverity, ReviewStatus, SearchStatus } from './schemas';

export interface ImageFilters {
  make?: string;
  model?: string;
  year?: number;
  make_confirmed?: boolean;
  year_confirmed?: boolean;
  review_status?: ReviewStatus;
  download_status?: 'not_downloaded' | 'downloading' | 'downloaded' | 'failed';
}

export interface ErrorFilters {
  context?: ErrorContext;
  severity?: ErrorSeverity;
}

export const queryKeys = {
  images: (filters: ImageFilters = {}) => ['images', filters] as const,
  image: (id: number) => ['images', id] as const,
  searchImages: (searchId: number, filters: ImageFilters = {}) =>
    ['searches', searchId, 'images', filters] as const,
  searches: (status?: SearchStatus) => ['searches', { status }] as const,
  search: (id: number) => ['searches', id] as const,
  health: () => ['health'] as const,
  errors: (filters: ErrorFilters = {}) => ['errors', filters] as const,
};
