import { fireEvent, render, screen } from '@testing-library/react-native';

import { suggest, Suggestions } from '../Suggestions';

const MAKES = [
  'BMW',
  'Ford',
  'Honda',
  'Hyundai',
  'Kia',
  'Mercedes-Benz',
  'Nissan',
  'Tesla',
  'Toyota',
  'Volkswagen',
];

describe('suggest', () => {
  it('matches the start of a name, ignoring case', () => {
    expect(suggest(MAKES, 'h')).toEqual(['Honda', 'Hyundai']);
    expect(suggest(MAKES, 'TO')).toEqual(['Toyota']);
  });

  it('matches only the start, not anywhere in the name', () => {
    expect(suggest(MAKES, 'onda')).toEqual([]);
  });

  it('offers at most five', () => {
    expect(suggest(['Ma', 'Mb', 'Mc', 'Md', 'Me', 'Mf', 'Mg'], 'm')).toEqual([
      'Ma',
      'Mb',
      'Mc',
      'Md',
      'Me',
    ]);
  });

  it('offers nothing for an empty field', () => {
    // Five arbitrary makes under a box nobody has typed in yet are noise.
    expect(suggest(MAKES, '')).toEqual([]);
    expect(suggest(MAKES, '   ')).toEqual([]);
  });

  it('stops offering a name once the field holds it exactly', () => {
    // Which is what a tap leaves behind; the row would otherwise echo the
    // field back at the user.
    expect(suggest(MAKES, 'Kia')).toEqual([]);
    // A case-only difference still offers the catalogue's spelling.
    expect(suggest(MAKES, 'kia')).toEqual(['Kia']);
  });

  it('ignores spaces around what was typed', () => {
    expect(suggest(MAKES, ' tes')).toEqual(['Tesla']);
  });
});

describe('<Suggestions />', () => {
  it('renders nothing when there is nothing to suggest', () => {
    render(<Suggestions items={[]} onPick={jest.fn()} />);

    expect(screen.queryAllByRole('button')).toHaveLength(0);
  });

  it('reports the tapped suggestion', () => {
    const onPick = jest.fn();
    render(<Suggestions items={['Honda', 'Hyundai']} onPick={onPick} />);

    fireEvent.press(screen.getByLabelText('Hyundai'));

    expect(onPick).toHaveBeenCalledWith('Hyundai');
  });
});
