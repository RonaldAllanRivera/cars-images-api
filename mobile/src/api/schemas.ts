import { z } from 'zod';

/** The three review verdicts. Never write make_confirmed from the client. */
export const ReviewStatusSchema = z.enum(['pending', 'approved', 'rejected']);
export type ReviewStatus = z.infer<typeof ReviewStatusSchema>;

export const DownloadStatusSchema = z.enum([
  'not_downloaded',
  'downloading',
  'downloaded',
  'failed',
]);

export const SearchStatusSchema = z.enum(['pending', 'running', 'completed', 'failed']);
export type SearchStatus = z.infer<typeof SearchStatusSchema>;

export const ErrorContextSchema = z.enum([
  'csv_upload',
  'csv_row',
  'search_run',
  'image_download',
  'wikimedia_block',
]);
export type ErrorContext = z.infer<typeof ErrorContextSchema>;

export const TokenAbilitySchema = z.enum([
  'search:read',
  'search:write',
  'review:write',
  'errors:read',
]);

export const UserSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  email: z.string(),
});
export type User = z.infer<typeof UserSchema>;

export const ImageSchema = z.object({
  id: z.number().int(),
  car_search_id: z.number().int().nullable(),
  make: z.string(),
  model: z.string().nullable(),
  year: z.number().int(),
  color: z.string().nullable(),
  title: z.string(),
  description: z.string().nullable(),
  source_url: z.string(),
  thumbnail_url: z.string().nullable(),
  width: z.number().int().nullable(),
  height: z.number().int().nullable(),
  license: z.string().nullable(),
  attribution: z.string().nullable(),
  // Nullable is the machine's "unknown" verdict - neither true nor false.
  make_confirmed: z.boolean().nullable(),
  year_confirmed: z.boolean().nullable(),
  review_status: ReviewStatusSchema,
  reviewed_by: z.number().int().nullable(),
  reviewed_at: z.string().nullable(),
  download_status: DownloadStatusSchema,
  created_at: z.string().nullable(),
});
export type Image = z.infer<typeof ImageSchema>;

export const SearchSchema = z.object({
  id: z.number().int(),
  make: z.string(),
  model: z.string().nullable(),
  commons_category: z.string().nullable(),
  from_year: z.number().int(),
  to_year: z.number().int(),
  color: z.string().nullable(),
  transmission: z.string().nullable(),
  transparent_background: z.boolean(),
  images_per_year: z.number().int(),
  status: SearchStatusSchema,
  csv_import_id: z.number().int().nullable(),
  requested_by: z.number().int().nullable(),
  // whenCounted / whenLoaded: present only on the endpoints that add them.
  images_count: z.number().int().optional(),
  images: z.array(ImageSchema).optional(),
  created_at: z.string().nullable(),
  updated_at: z.string().nullable(),
});
export type Search = z.infer<typeof SearchSchema>;

export const ErrorEventSchema = z.object({
  id: z.number().int(),
  context: ErrorContextSchema,
  severity: z.string(),
  message: z.string().nullable(),
  exception_class: z.string().nullable(),
  exception_message: z.string().nullable(),
  trace_excerpt: z.string().nullable(),
  details: z.unknown().nullable(),
  car_search_id: z.number().int().nullable(),
  csv_import_id: z.number().int().nullable(),
  car_image_id: z.number().int().nullable(),
  occurred_at: z.string().nullable(),
});
export type ErrorEvent = z.infer<typeof ErrorEventSchema>;

export const HealthSummarySchema = z.object({
  searches_by_status: z.record(SearchStatusSchema, z.number().int()),
  errors_last_24h: z.number().int(),
  errors_by_context_last_7d: z.record(ErrorContextSchema, z.number().int()),
  images_last_7d: z.number().int(),
  latest_error_at: z.string().nullable(),
});
export type HealthSummary = z.infer<typeof HealthSummarySchema>;

/** POST /auth/login is the ONE endpoint with no `data` envelope. */
export const LoginResponseSchema = z.object({
  token: z.string(),
  token_type: z.literal('Bearer'),
  abilities: z.array(TokenAbilitySchema),
  user: UserSchema,
});
export type LoginResponse = z.infer<typeof LoginResponseSchema>;

/** A 422 from any Form Request. */
export const ValidationErrorSchema = z.object({
  message: z.string(),
  errors: z.record(z.string(), z.array(z.string())),
});
export type ValidationError = z.infer<typeof ValidationErrorSchema>;

/** Every single-resource endpoint except login. */
export const single = <T extends z.ZodTypeAny>(schema: T) => z.object({ data: schema });

/**
 * Laravel's cursor envelope. `meta.next_cursor` is null on the last page -
 * that null is the infinite-scroll terminator.
 */
export const cursorPage = <T extends z.ZodTypeAny>(schema: T) =>
  z.object({
    data: z.array(schema),
    links: z.object({
      first: z.string().nullable(),
      last: z.string().nullable(),
      prev: z.string().nullable(),
      next: z.string().nullable(),
    }),
    meta: z.object({
      path: z.string(),
      per_page: z.number().int(),
      next_cursor: z.string().nullable(),
      prev_cursor: z.string().nullable(),
    }),
  });

export type CursorPage<T> = {
  data: T[];
  links: { first: string | null; last: string | null; prev: string | null; next: string | null };
  meta: { path: string; per_page: number; next_cursor: string | null; prev_cursor: string | null };
};
