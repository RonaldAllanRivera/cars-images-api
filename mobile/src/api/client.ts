import type { z } from 'zod';

import { ValidationErrorSchema } from './schemas';

export type QueryParams = Record<string, string | number | boolean | null | undefined>;

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly body: unknown = undefined,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

export class ApiValidationError extends ApiError {
  constructor(
    message: string,
    public readonly fieldErrors: Record<string, string[]>,
    body: unknown,
  ) {
    super(422, message, body);
    this.name = 'ApiValidationError';
  }

  /** The first message for a field, for rendering under an input. */
  first(field: string): string | undefined {
    return this.fieldErrors[field]?.[0];
  }
}

interface ClientConfig {
  onUnauthorized: () => void;
  getToken: () => string | null;
}

let config: ClientConfig = {
  onUnauthorized: () => {},
  getToken: () => null,
};

export function configureApiClient(next: ClientConfig): void {
  config = next;
}

/**
 * Inlined at build time by Metro. There is no runtime config file to fetch,
 * so a deployed build cannot be pointed at the wrong backend by accident.
 */
const BASE_URL = process.env.EXPO_PUBLIC_API_URL ?? 'http://localhost:8000';

function buildUrl(path: string, query?: QueryParams): string {
  const url = new URL(`/api/v1${path}`, BASE_URL);

  for (const [key, value] of Object.entries(query ?? {})) {
    // Absent is not the same as empty: the list filters are `sometimes`
    // rules, so an empty string would 422 rather than mean "no filter".
    if (value === null || value === undefined || value === '') continue;

    url.searchParams.set(key, String(value));
  }

  return url.toString();
}

interface RequestOptions<T> {
  method?: 'GET' | 'POST' | 'PATCH' | 'DELETE';
  body?: unknown;
  query?: QueryParams;
  schema?: z.ZodType<T>;
}

export async function apiRequest<T = unknown>(
  path: string,
  options: RequestOptions<T> = {},
): Promise<T> {
  const { method = 'GET', body, query, schema } = options;
  const token = config.getToken();

  const headers: Record<string, string> = { Accept: 'application/json' };
  if (token) headers.Authorization = `Bearer ${token}`;
  if (body !== undefined) headers['Content-Type'] = 'application/json';

  const response = await fetch(buildUrl(path, query), {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  });

  if (response.status === 204) {
    return undefined as T;
  }

  const payload: unknown = await response.json().catch(() => undefined);

  if (!response.ok) {
    throw toError(response.status, payload, config);
  }

  if (!schema) {
    return payload as T;
  }

  const parsed = schema.safeParse(payload);

  if (!parsed.success) {
    // Drift between the PHP Resource and this schema surfaces here, at the
    // fetch, naming the field - not as a crash three screens later.
    throw new ApiError(
      response.status,
      `The API response did not match the client contract for ${path}: ${parsed.error.issues
        .map((issue) => `${issue.path.join('.')} ${issue.message}`)
        .join('; ')}`,
      payload,
    );
  }

  return parsed.data;
}

function toError(status: number, payload: unknown, current: ClientConfig): ApiError {
  if (status === 401) {
    // The single place a dead token signs the user out. A 403 must NOT do
    // this: it means the token is alive but lacks an ability.
    current.onUnauthorized();

    return new ApiError(401, 'Your session has expired. Please sign in again.', payload);
  }

  if (status === 422) {
    const parsed = ValidationErrorSchema.safeParse(payload);

    if (parsed.success) {
      return new ApiValidationError(parsed.data.message, parsed.data.errors, payload);
    }
  }

  if (status === 429) {
    return new ApiError(429, 'Too many requests. Wait a moment and try again.', payload);
  }

  const message =
    typeof payload === 'object' && payload !== null && 'message' in payload
      ? String((payload as { message: unknown }).message)
      : `The request failed (${status}).`;

  return new ApiError(status, message, payload);
}
