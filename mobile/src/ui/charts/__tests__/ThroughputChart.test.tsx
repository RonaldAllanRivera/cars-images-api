import { fireEvent, render, screen, within } from '@testing-library/react-native';

import { ThroughputChart } from '../ThroughputChart';

const days = ['2026-09-13', '2026-09-14', '2026-09-15'];
const chart = <ThroughputChart days={days} completed={[4, 0, 2]} failed={[1, 0, 0]} />;

describe('<ThroughputChart />', () => {
  it('draws one bar per day, each labelled with its figures', () => {
    // A screen reader reads the data a sighted user reads off the bars.
    render(chart);

    expect(screen.getAllByRole('button')).toHaveLength(3);
    expect(screen.getByLabelText('13 September: 4 completed, 1 failed')).toBeTruthy();
    expect(screen.getByLabelText('15 September: 2 completed, 0 failed')).toBeTruthy();
  });

  it('draws only the segments a day has, and nothing for a quiet day', () => {
    // An empty day must be visibly empty: a stub of colour would read as
    // "something ran".
    render(chart);

    const quiet = screen.getByLabelText('14 September: 0 completed, 0 failed');
    expect(within(quiet).queryByTestId('segment-completed')).toBeNull();
    expect(within(quiet).queryByTestId('segment-failed')).toBeNull();

    const both = screen.getByLabelText('13 September: 4 completed, 1 failed');
    expect(within(both).getByTestId('segment-completed')).toBeTruthy();
    expect(within(both).getByTestId('segment-failed')).toBeTruthy();

    const completedOnly = screen.getByLabelText('15 September: 2 completed, 0 failed');
    expect(within(completedOnly).queryByTestId('segment-failed')).toBeNull();
  });

  it("shows a tapped day's figures, and the window's totals after a second tap", () => {
    // A phone has no hover: tapping a bar is the tooltip.
    render(chart);
    expect(screen.getByText('6 completed, 1 failed in 3 days')).toBeTruthy();

    fireEvent.press(screen.getByLabelText('13 September: 4 completed, 1 failed'));
    expect(screen.getByText('13 September: 4 completed, 1 failed')).toBeTruthy();

    fireEvent.press(screen.getByLabelText('13 September: 4 completed, 1 failed'));
    expect(screen.getByText('6 completed, 1 failed in 3 days')).toBeTruthy();
  });

  it('names both series in a legend, so colour never carries the meaning alone', () => {
    // Red against green is marginal under deuteranopia (ΔE 8.1).
    render(chart);

    expect(screen.getByText('Completed')).toBeTruthy();
    expect(screen.getByText('Failed')).toBeTruthy();
  });

  it('scales an all-zero window without dividing by zero', () => {
    render(<ThroughputChart days={days} completed={[0, 0, 0]} failed={[0, 0, 0]} />);

    expect(screen.queryAllByTestId(/^segment-/)).toHaveLength(0);
    // The axis tops out at 1 rather than at 0 or NaN.
    expect(screen.getByText('1')).toBeTruthy();
  });
});
