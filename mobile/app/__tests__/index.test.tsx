import { render, screen } from '@testing-library/react-native';

import Landing from '../index';

describe('<Landing />', () => {
  it('names the app and offers a way in', () => {
    render(<Landing />);

    expect(screen.getByText('Cars Images')).toBeTruthy();
    expect(screen.getByText('Sign in')).toBeTruthy();
  });
});
