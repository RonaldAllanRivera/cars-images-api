import { fireEvent, render, screen } from '@testing-library/react-native';

import { FailuresByKindChart } from '../FailuresByKindChart';

const days = ['2026-09-14', '2026-09-15'];

describe('<FailuresByKindChart />', () => {
  it('draws one small multiple per kind that failed, titled by its label', () => {
    render(
      <FailuresByKindChart days={days} series={{ search_run: [0, 2], wikimedia_block: [1, 0] }} />,
    );

    expect(screen.getByText('Search run')).toBeTruthy();
    expect(screen.getByText('Wikimedia block')).toBeTruthy();
    // Absent means zero, and zero gets no chart.
    expect(screen.queryByText('CSV row')).toBeNull();
    expect(screen.getByLabelText('Search run, 15 September: 2')).toBeTruthy();
  });

  it('says so when nothing failed, rather than drawing an empty chart', () => {
    render(<FailuresByKindChart days={days} series={{}} />);

    expect(screen.getByText('No failures in the last 2 days.')).toBeTruthy();
    expect(screen.queryAllByRole('button')).toHaveLength(0);
  });

  it('puts every kind on one scale so they compare honestly', () => {
    // On separate scales, one failure in a quiet kind would draw as tall as
    // twenty in a busy one.
    render(<FailuresByKindChart days={days} series={{ search_run: [0, 20], csv_row: [1, 0] }} />);

    expect(screen.getAllByText('20')).toHaveLength(2);
  });

  it("shows a tapped day's count in that kind's own title row", () => {
    render(<FailuresByKindChart days={days} series={{ search_run: [0, 2] }} />);
    expect(screen.getByText('2 in 2 days')).toBeTruthy();

    fireEvent.press(screen.getByLabelText('Search run, 15 September: 2'));

    expect(screen.getByText('15 September: 2')).toBeTruthy();
  });

  it('charts a kind the app does not know under its raw key', () => {
    render(<FailuresByKindChart days={days} series={{ meteor_strike: [1, 0] }} />);

    expect(screen.getByText('meteor_strike')).toBeTruthy();
  });
});
