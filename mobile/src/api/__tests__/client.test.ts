import { z } from 'zod';

import { ApiError, apiRequest, ApiValidationError, configureApiClient } from '../client';

const json = (body: unknown, status = 200) =>
  Promise.resolve(
    new Response(JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json' },
    }),
  );

describe('apiRequest', () => {
  let onUnauthorized: jest.Mock;
  let token: string | null;

  beforeEach(() => {
    token = 'test-token';
    onUnauthorized = jest.fn();
    configureApiClient({ onUnauthorized, getToken: () => token });
    global.fetch = jest.fn();
  });

  it('sends the bearer token and asks for JSON', async () => {
    jest.mocked(global.fetch).mockReturnValueOnce(json({ data: { id: 1 } }));

    await apiRequest('/images/1');

    const [url, init] = jest.mocked(global.fetch).mock.calls[0]!;
    expect(String(url)).toContain('/api/v1/images/1');
    expect((init?.headers as Record<string, string>).Authorization).toBe('Bearer test-token');
    expect((init?.headers as Record<string, string>).Accept).toBe('application/json');
  });

  it('omits the Authorization header when signed out', async () => {
    token = null;
    jest.mocked(global.fetch).mockReturnValueOnce(json({ token: 'x' }, 201));

    await apiRequest('/auth/login', { method: 'POST', body: { email: 'a@b.c' } });

    const [, init] = jest.mocked(global.fetch).mock.calls[0]!;
    expect((init?.headers as Record<string, string>).Authorization).toBeUndefined();
  });

  it('serialises query params and encodes booleans as true/false strings', async () => {
    jest.mocked(global.fetch).mockReturnValueOnce(json({ data: [] }));

    await apiRequest('/images', {
      query: { make: 'Toyota', make_confirmed: true, year: 1997, model: null, color: undefined },
    });

    const url = String(jest.mocked(global.fetch).mock.calls[0]![0]);
    expect(url).toContain('make=Toyota');
    // The Form Request remaps "true"/"false"; 1/0 would also pass, but the
    // string spelling is what a JS client naturally sends and what is tested.
    expect(url).toContain('make_confirmed=true');
    expect(url).toContain('year=1997');
    // Null and undefined are omitted entirely rather than sent empty, which
    // would 422 against the `sometimes` rules.
    expect(url).not.toContain('model=');
    expect(url).not.toContain('color=');
  });

  it('validates the response against the schema', async () => {
    jest.mocked(global.fetch).mockReturnValueOnce(json({ id: 1, name: 'Ada' }));

    const schema = z.object({ id: z.number(), name: z.string() });

    await expect(apiRequest('/whatever', { schema })).resolves.toEqual({ id: 1, name: 'Ada' });
  });

  it('throws when the response does not match the schema', async () => {
    jest.mocked(global.fetch).mockReturnValueOnce(json({ id: 'not-a-number' }));

    const schema = z.object({ id: z.number() });

    await expect(apiRequest('/whatever', { schema })).rejects.toThrow(/contract/i);
  });

  it('calls onUnauthorized exactly once for a 401 and still throws', async () => {
    jest.mocked(global.fetch).mockReturnValueOnce(json({ message: 'Unauthenticated.' }, 401));

    await expect(apiRequest('/images')).rejects.toBeInstanceOf(ApiError);
    expect(onUnauthorized).toHaveBeenCalledTimes(1);
  });

  it('does not sign the user out on a 403', async () => {
    jest.mocked(global.fetch).mockReturnValueOnce(json({ message: 'Forbidden.' }, 403));

    // A missing ability is not a dead token - signing out would be wrong.
    await expect(apiRequest('/errors')).rejects.toMatchObject({ status: 403 });
    expect(onUnauthorized).not.toHaveBeenCalled();
  });

  it('exposes field errors from a 422', async () => {
    jest.mocked(global.fetch).mockReturnValueOnce(
      json({ message: 'The given data was invalid.', errors: { to_year: ['Too wide.'] } }, 422),
    );

    await expect(apiRequest('/searches', { method: 'POST', body: {} })).rejects.toBeInstanceOf(
      ApiValidationError,
    );
  });

  it('returns undefined for a 204', async () => {
    jest
      .mocked(global.fetch)
      .mockReturnValueOnce(Promise.resolve(new Response(null, { status: 204 })));

    await expect(apiRequest('/auth/logout', { method: 'POST' })).resolves.toBeUndefined();
  });
});
