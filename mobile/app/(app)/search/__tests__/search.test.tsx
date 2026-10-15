import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import type { ReactNode } from 'react';
import { ScrollView } from 'react-native';

import makesFixture from '@/api/__fixtures__/makes.json';
import searchFixture from '@/api/__fixtures__/search-create.json';
import * as client from '@/api/client';
import { MAX_YEAR_SPAN } from '@/api/hooks/useCreateSearch';
import SearchForm from '../index';

// The screen only ever calls `router` from a press handler, so a plain object
// is enough - and it keeps the test off react-navigation entirely.
jest.mock('expo-router', () => ({ router: { push: jest.fn(), replace: jest.fn() } }));

const wrapper = ({ children }: { children: ReactNode }) => {
  // gcTime: 0 on both caches - the mutation cache keeps its own GC timer, and
  // a timer left scheduled outlives the test as a real handle.
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0 }, mutations: { gcTime: 0 } },
  });

  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
};

// By label, not by placeholder. Every field carries a real label now, and a
// label is what the field is; a placeholder is a disposable hint that can be
// reworded without the form changing meaning.
const fillIn = (make: string, fromYear: string, toYear: string) => {
  fireEvent.changeText(screen.getByLabelText('Make'), make);
  fireEvent.changeText(screen.getByLabelText('From year'), fromYear);
  fireEvent.changeText(screen.getByLabelText('To year'), toYear);
  fireEvent.press(screen.getByLabelText('Run search'));
};

beforeEach(() => {
  jest.restoreAllMocks();
  // The form fetches the make catalogue on mount. Answered here so no test
  // reaches for a real network, and so suggestions have something to show.
  // apiRequest, not apiRequestRaw: the cap tests' "did not call the API"
  // assertions watch apiRequestRaw, which only the submit uses.
  jest.spyOn(client, 'apiRequest').mockResolvedValue(makesFixture);
});

describe('<SearchForm /> cap enforcement', () => {
  it('refuses a year range wider than the cap without asking the API', async () => {
    // The cap is a global constraint of this client: the search runs inline
    // inside the request, so the user has to see the limit before the wait
    // rather than after a round trip that 422s.
    const request = jest.spyOn(client, 'apiRequestRaw');

    render(<SearchForm />, { wrapper });
    fillIn('Toyota', '1990', '2000');

    await waitFor(() =>
      expect(
        screen.getByText(
          `The year range may span at most ${MAX_YEAR_SPAN} years, because the search runs inside the request.`,
        ),
      ).toBeTruthy(),
    );
    expect(request).not.toHaveBeenCalled();
  });

  it('submits a range inside the cap', async () => {
    // The control for the test above: without it, a submit button that never
    // fires at all would pass the "did not call the API" assertion too.
    const request = jest
      .spyOn(client, 'apiRequestRaw')
      .mockResolvedValue({ status: 201, body: searchFixture });

    render(<SearchForm />, { wrapper });
    fillIn('Toyota', '1997', '1999');

    await waitFor(() => expect(request).toHaveBeenCalledTimes(1));
    expect(request.mock.calls[0]?.[0]).toBe('/searches');
    expect(screen.queryByText(/may span at most/)).toBeNull();
  });
});

describe('<SearchForm /> suggestions', () => {
  it('suggests makes from what has been typed, and fills the field when one is tapped', async () => {
    render(<SearchForm />, { wrapper });

    fireEvent.changeText(screen.getByLabelText('Make'), 'ho');
    await waitFor(() => expect(screen.getByLabelText('Honda')).toBeTruthy());

    fireEvent.press(screen.getByLabelText('Honda'));

    expect(screen.getByLabelText('Make').props.value).toBe('Honda');
    expect(screen.queryByLabelText('Honda')).toBeNull();
  });

  it("suggests the chosen make's models, whatever case the make was typed in", async () => {
    render(<SearchForm />, { wrapper });

    fireEvent.changeText(screen.getByLabelText('Make'), 'honda');
    fireEvent.changeText(screen.getByLabelText('Model'), 'c');

    await waitFor(() => expect(screen.getByLabelText('Civic')).toBeTruthy());
    expect(screen.getByLabelText('CR-V')).toBeTruthy();
  });

  it('offers no models for a make outside the catalogue', async () => {
    render(<SearchForm />, { wrapper });

    // Wait for the catalogue to land first, or "no suggestions" proves nothing.
    fireEvent.changeText(screen.getByLabelText('Make'), 'ho');
    await waitFor(() => expect(screen.getByLabelText('Honda')).toBeTruthy());

    fireEvent.changeText(screen.getByLabelText('Make'), 'Lada');
    fireEvent.changeText(screen.getByLabelText('Model'), 'c');

    expect(screen.queryByLabelText('Civic')).toBeNull();
  });

  it('still submits a make the catalogue does not list', async () => {
    // Suggest, never constrain: every search reaches Wikimedia, and the
    // catalogue is ten makes.
    const request = jest
      .spyOn(client, 'apiRequestRaw')
      .mockResolvedValue({ status: 201, body: searchFixture });

    render(<SearchForm />, { wrapper });
    fillIn('Lada', '1997', '1999');

    await waitFor(() => expect(request).toHaveBeenCalledTimes(1));
    expect(request.mock.calls[0]?.[1]).toMatchObject({ body: { make: 'Lada' } });
  });

  it('works as plain text when the catalogue cannot be read', async () => {
    jest
      .spyOn(client, 'apiRequest')
      .mockRejectedValue(new client.ApiError(403, 'This action is unauthorized.'));
    const request = jest
      .spyOn(client, 'apiRequestRaw')
      .mockResolvedValue({ status: 201, body: searchFixture });

    render(<SearchForm />, { wrapper });
    fillIn('Toyota', '1997', '1999');

    await waitFor(() => expect(request).toHaveBeenCalledTimes(1));
    // Suggestions are a convenience; their failure is not the user's problem.
    expect(screen.queryByText('This action is unauthorized.')).toBeNull();
  });

  it('lets a tap on a suggestion through while the keyboard is up', async () => {
    // With the default ("never"), the first tap only dismisses the keyboard
    // and the user has to tap the suggestion a second time.
    render(<SearchForm />, { wrapper });

    fireEvent.changeText(screen.getByLabelText('Make'), 'ho');
    await waitFor(() => expect(screen.getByLabelText('Honda')).toBeTruthy());

    expect(screen.UNSAFE_getByType(ScrollView).props.keyboardShouldPersistTaps).toBe('handled');
  });
});
