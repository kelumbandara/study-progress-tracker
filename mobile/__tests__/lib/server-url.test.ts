import { normalizeServerUrl } from '@/lib/server-url';

const ok = (input: string, allowHttp = false) => {
  const r = normalizeServerUrl(input, allowHttp);
  if (!r.ok) throw new Error(`expected ok for "${input}" but got: ${r.error}`);
  return r.url;
};
const bad = (input: string, allowHttp = false) => {
  const r = normalizeServerUrl(input, allowHttp);
  if (r.ok) throw new Error(`expected an error for "${input}" but got ${r.url}`);
  return r.error;
};

describe('normalizeServerUrl', () => {
  test('accepts a normal https address and tidies it', () => {
    expect(ok('https://tracker.example.com')).toBe('https://tracker.example.com');
    expect(ok('  https://tracker.example.com/  ')).toBe('https://tracker.example.com');
    expect(ok('HTTPS://Tracker.Example.COM')).toBe('https://tracker.example.com');
    expect(ok('https://tracker.example.com:8443')).toBe('https://tracker.example.com:8443');
  });

  test('assumes https when the scheme is missing', () => {
    expect(ok('tracker.example.com')).toBe('https://tracker.example.com');
    expect(ok('tracker.example.com/login')).toBe('https://tracker.example.com');
  });

  test('drops pasted paths, queries and fragments', () => {
    expect(ok('https://tracker.example.com/login')).toBe('https://tracker.example.com');
    expect(ok('https://tracker.example.com/api/v1')).toBe('https://tracker.example.com');
    expect(ok('https://tracker.example.com/?x=1#top')).toBe('https://tracker.example.com');
  });

  test('release builds refuse plain http, with a helpful message', () => {
    expect(bad('http://tracker.example.com')).toMatch(/https/i);
    expect(bad('http://192.168.1.20:8080')).toMatch(/https/i);
  });

  test('development builds allow http and guess it for local addresses', () => {
    expect(ok('http://192.168.1.20:8080', true)).toBe('http://192.168.1.20:8080');
    expect(ok('192.168.1.20:8080', true)).toBe('http://192.168.1.20:8080');
    expect(ok('localhost:8080', true)).toBe('http://localhost:8080');
    expect(ok('mycomputer.local:8080', true)).toBe('http://mycomputer.local:8080');
    expect(ok('tracker.example.com', true)).toBe('https://tracker.example.com'); // a real domain is still https
  });

  test('refuses empty, spaced and nonsense input', () => {
    expect(bad('')).toMatch(/enter the server address/i);
    expect(bad('   ')).toMatch(/enter the server address/i);
    expect(bad('tracker example.com')).toMatch(/spaces/i);
    expect(bad('https://')).toMatch(/web address/i);
    expect(bad('tracker')).toMatch(/full domain/i);
  });

  test('refuses other schemes and embedded credentials', () => {
    expect(bad('ftp://tracker.example.com')).toMatch(/https/i);
    expect(bad('javascript://tracker.example.com/%0Aalert(1)')).toMatch(/https/i);
    expect(bad('file:///etc/passwd')).toBeTruthy();
    expect(bad('https://user:secret@tracker.example.com')).toMatch(/user name or password/i);
    expect(bad('https://user@tracker.example.com')).toMatch(/user name or password/i);
  });
});
