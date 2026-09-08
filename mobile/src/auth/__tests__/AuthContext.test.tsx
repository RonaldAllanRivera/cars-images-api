import { act, renderHook, waitFor } from '@testing-library/react-native';
import type { ReactNode } from 'react';

import * as client from '../../api/client';
import { AuthProvider, useAuth } from '../AuthContext';
import { tokenStore } from '../TokenStore';

const wrapper = ({ children }: { children: ReactNode }) => <AuthProvider>{children}</AuthProvider>;

describe('useAuth', () => {
  beforeEach(async () => {
    jest.restoreAllMocks();
    await tokenStore.clear();
  });

  it('starts anonymous when nothing is stored', async () => {
    const { result } = renderHook(() => useAuth(), { wrapper });

    await waitFor(() => expect(result.current.status).toBe('anonymous'));
    expect(result.current.user).toBeNull();
  });

  it('restores a stored token and confirms it against /auth/me', async () => {
    await tokenStore.set('stored-token');
    jest
      .spyOn(client, 'apiRequest')
      .mockResolvedValueOnce({ data: { id: 7, name: 'Ada', email: 'ada@example.test' } });

    const { result } = renderHook(() => useAuth(), { wrapper });

    await waitFor(() => expect(result.current.status).toBe('authenticated'));
    expect(result.current.user?.id).toBe(7);
  });

  it('discards a stored token the API rejects', async () => {
    await tokenStore.set('revoked-token');
    jest
      .spyOn(client, 'apiRequest')
      .mockRejectedValueOnce(new client.ApiError(401, 'Your session has expired.'));

    const { result } = renderHook(() => useAuth(), { wrapper });

    await waitFor(() => expect(result.current.status).toBe('anonymous'));
    expect(await tokenStore.get()).toBeNull();
  });

  it('stores the token on a successful sign-in', async () => {
    jest.spyOn(client, 'apiRequest').mockResolvedValueOnce({
      token: 'fresh-token',
      token_type: 'Bearer',
      abilities: ['search:read', 'search:write', 'review:write', 'errors:read'],
      user: { id: 7, name: 'Ada', email: 'ada@example.test' },
    });

    const { result } = renderHook(() => useAuth(), { wrapper });
    await waitFor(() => expect(result.current.status).toBe('anonymous'));

    await act(async () => {
      await result.current.signIn('ada@example.test', 'password', 'test-device');
    });

    expect(result.current.status).toBe('authenticated');
    expect(await tokenStore.get()).toBe('fresh-token');
  });

  it('clears the token on sign-out even if the API call fails', async () => {
    await tokenStore.set('stored-token');
    jest
      .spyOn(client, 'apiRequest')
      .mockResolvedValueOnce({ data: { id: 7, name: 'Ada', email: 'ada@example.test' } })
      .mockRejectedValueOnce(new client.ApiError(500, 'Server error'));

    const { result } = renderHook(() => useAuth(), { wrapper });
    await waitFor(() => expect(result.current.status).toBe('authenticated'));

    await act(async () => {
      await result.current.signOut();
    });

    // A failed revoke must not strand the user in a signed-in shell with a
    // token the server has already forgotten.
    expect(result.current.status).toBe('anonymous');
    expect(await tokenStore.get()).toBeNull();
  });
});
