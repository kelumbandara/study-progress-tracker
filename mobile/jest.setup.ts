// Shared test setup: an in-memory stand-in for the device's secure storage.
// (Jest only lets a mock factory use variables whose names start with "mock".)
const mockStore = new Map<string, string>();

jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn(async (key: string) => mockStore.get(key) ?? null),
  setItemAsync: jest.fn(async (key: string, value: string) => void mockStore.set(key, value)),
  deleteItemAsync: jest.fn(async (key: string) => void mockStore.delete(key)),
}));

beforeEach(() => mockStore.clear());
