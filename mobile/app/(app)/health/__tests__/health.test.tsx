import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react-native';
import type { ReactNode } from 'react';

import errorsFixture from '@/api/__fixtures__/errors.json';
import healthFixture from '@/api/__fixtures__/health.json';
import * as client from '@/api/client';
import Health from '../index';

jest.mock('@/auth/AuthContext', () => ({
  useAuth: () => ({ status: 'authenticated', user: null, signIn: jest.fn(), signOut: jest.fn() }),
}));

const wrapper = ({ children }: { children: ReactNode }) => {
  // gcTime: 0 disposes the cache (and its GC timer) as soon as nothing
  // observes it, instead of leaving it scheduled for the default 5 minutes.
  // Without this the timer outlives the test as a real handle and Jest
  // hangs past its normal exit - `--detectOpenHandles` catches it directly.
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });

  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
};

describe('<Health />', () => {
  it('renders the counters and the error log', async () => {
    jest.spyOn(client, 'apiRequest').mockImplementation((path) =>
      Promise.resolve(path === '/health/summary' ? healthFixture : errorsFixture),
    );

    render(<Health />, { wrapper });

    await waitFor(() => expect(screen.getByText('completed')).toBeTruthy());

    // getAllByText, not getByText: "Search run" legitimately renders twice -
    // once as the "Errors by context, 7d" counter label and once on the
    // fixture's own logged event - and the fixture's error message ("The
    // search run failed.") independently contains the same words, so a
    // single-match query is inherently ambiguous against this fixture.
    expect(screen.getAllByText(/search run/i).length).toBeGreaterThan(0);
  });
});
