// The web TokenStore path reads localStorage; jsdom provides it, but the
// native path's SecureStore must be mocked or every suite touching auth
// tries to reach a native module that does not exist under Jest.
jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn(async () => null),
  setItemAsync: jest.fn(async () => undefined),
  deleteItemAsync: jest.fn(async () => undefined),
}));
