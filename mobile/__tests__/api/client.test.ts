import { createApiClient } from '@/api/client';
import { ApiError } from '@/api/errors';

type FakeResponse = { status: number; body?: unknown; rawText?: string };

function fakeFetch(respond: (url: string, init: RequestInit) => FakeResponse | Promise<FakeResponse>) {
  const calls: { url: string; init: RequestInit }[] = [];
  const impl = jest.fn(async (url: RequestInfo | URL, init?: RequestInit) => {
    calls.push({ url: String(url), init: init ?? {} });
    const r = await respond(String(url), init ?? {});
    const text = r.rawText ?? (r.body === undefined ? '' : JSON.stringify(r.body));
    return { ok: r.status >= 200 && r.status < 300, status: r.status, text: async () => text } as unknown as Response;
  });
  return { impl: impl as unknown as typeof fetch, calls };
}

const make = (f: ReturnType<typeof fakeFetch>, extra: Partial<Parameters<typeof createApiClient>[0]> = {}) =>
  createApiClient({ getBaseUrl: () => 'https://tracker.example.com', getToken: () => 'tok123', fetchImpl: f.impl, ...extra });

describe('api client', () => {
  test('builds the URL, sends the bearer token and parses JSON', async () => {
    const f = fakeFetch(() => ({ status: 200, body: { today: '2026-11-10' } }));
    const r = await make(f).get<{ today: string }>('/bootstrap');
    expect(r.today).toBe('2026-11-10');
    expect(f.calls[0]?.url).toBe('https://tracker.example.com/api/v1/bootstrap');
    const headers = f.calls[0]?.init.headers as Record<string, string>;
    expect(headers.Authorization).toBe('Bearer tok123');
    expect(headers.Accept).toBe('application/json');
    expect(headers['Content-Type']).toBeUndefined(); // no body on a GET
  });

  test('sends JSON bodies with a content type, and encodes query strings', async () => {
    const f = fakeFetch(() => ({ status: 200, body: {} }));
    const c = make(f);
    await c.put('/daily-logs/2026-11-09', { minutes: { school: 1 } });
    await c.get('/papers', { subject_id: 1, year: undefined, q: 'a b&c' });
    expect((f.calls[0]?.init.headers as Record<string, string>)['Content-Type']).toBe('application/json');
    expect(f.calls[0]?.init.body).toBe(JSON.stringify({ minutes: { school: 1 } }));
    expect(f.calls[1]?.url).toBe('https://tracker.example.com/api/v1/papers?subject_id=1&q=a%20b%26c');
  });

  test('sign-in sends no token and a 401 there does not sign anyone out', async () => {
    const f = fakeFetch(() => ({ status: 401, body: { message: 'Unauthenticated.' } }));
    const onUnauthorized = jest.fn();
    const c = make(f, { onUnauthorized });
    await expect(c.post('/auth/login', { email: 'a' }, { auth: false })).rejects.toMatchObject({ kind: 'unauthorized' });
    expect((f.calls[0]?.init.headers as Record<string, string>).Authorization).toBeUndefined();
    expect(onUnauthorized).not.toHaveBeenCalled();
  });

  test('a 401 on a normal call tells the app to sign out, once per call', async () => {
    const f = fakeFetch(() => ({ status: 401, body: { message: 'Unauthenticated.' } }));
    const onUnauthorized = jest.fn();
    await expect(make(f, { onUnauthorized }).get('/dashboard')).rejects.toMatchObject({ kind: 'unauthorized', status: 401 });
    expect(onUnauthorized).toHaveBeenCalledTimes(1);
  });

  test('422 keeps the field errors and uses the first message as the main text', async () => {
    const f = fakeFetch(() => ({
      status: 422,
      body: { message: 'The minutes.papers field is required.', errors: { 'minutes.papers': ['Enter the papers time.'], rating: ['Pick 1 to 5.'] } },
    }));
    const e = (await make(f).put('/daily-logs/2026-11-09', {}).catch((x) => x)) as ApiError;
    expect(e).toBeInstanceOf(ApiError);
    expect(e.kind).toBe('validation');
    expect(e.message).toBe('Enter the papers time.');
    expect(e.fieldMessage('rating')).toBe('Pick 1 to 5.');
    expect(e.fieldMessage('minutes')).toBe('Enter the papers time.'); // matches "minutes.papers"
    expect(e.fieldMessage('notes')).toBeUndefined();
    expect(e.isRetryable).toBe(false);
  });

  test('422 without a field list falls back to the server message', async () => {
    const f = fakeFetch(() => ({ status: 422, body: { message: 'Mastery needs evidence.' } }));
    await expect(make(f).put('/syllabus/topics/1', {})).rejects.toMatchObject({ kind: 'validation', message: 'Mastery needs evidence.' });
  });

  test.each([
    [404, 'not_found', false],
    [429, 'rate_limited', true],
    [500, 'server', true],
    [502, 'server', true],
    [503, 'server', true],
  ])('HTTP %i becomes "%s" (retryable: %s)', async (status, kind, retryable) => {
    const f = fakeFetch(() => ({ status, body: { message: 'x' } }));
    const e = (await make(f).get('/me').catch((x) => x)) as ApiError;
    expect(e.kind).toBe(kind);
    expect(e.isRetryable).toBe(retryable);
  });

  test('an HTML error page from a proxy is a server error, not a crash', async () => {
    const f = fakeFetch(() => ({ status: 502, rawText: '<html><body>Bad Gateway</body></html>' }));
    await expect(make(f).get('/me')).rejects.toMatchObject({ kind: 'server', status: 502 });
  });

  test('a 200 that is not JSON is reported, not returned as garbage', async () => {
    const f = fakeFetch(() => ({ status: 200, rawText: '<html>captive portal</html>' }));
    await expect(make(f).get('/me')).rejects.toMatchObject({ kind: 'server' });
  });

  test('an empty 200 reply is allowed', async () => {
    const f = fakeFetch(() => ({ status: 200, rawText: '' }));
    await expect(make(f).get('/me')).resolves.toBeNull();
  });

  test('no connection gives a friendly network error', async () => {
    const impl = jest.fn(async () => {
      throw new TypeError('Network request failed');
    }) as unknown as typeof fetch;
    const e = (await createApiClient({ getBaseUrl: () => 'https://x.example.com', getToken: () => null, fetchImpl: impl }).get('/me').catch((x) => x)) as ApiError;
    expect(e.kind).toBe('network');
    expect(e.isRetryable).toBe(true);
    expect(e.message).toMatch(/cannot reach the server/i);
  });

  test('a slow server times out and the request is aborted', async () => {
    jest.useFakeTimers();
    let aborted = false;
    const impl = jest.fn((_url: RequestInfo | URL, init?: RequestInit) =>
      new Promise<Response>((_resolve, reject) => {
        init?.signal?.addEventListener('abort', () => {
          aborted = true;
          reject(new Error('aborted'));
        });
      })) as unknown as typeof fetch;
    const p = createApiClient({ getBaseUrl: () => 'https://x.example.com', getToken: () => null, fetchImpl: impl, timeoutMs: 5000 }).get('/me');
    const assertion = expect(p).rejects.toMatchObject({ kind: 'timeout' });
    await jest.advanceTimersByTimeAsync(5001);
    await assertion;
    expect(aborted).toBe(true);
    jest.useRealTimers();
  });

  test('without a server address nothing is sent', async () => {
    const f = fakeFetch(() => ({ status: 200, body: {} }));
    await expect(createApiClient({ getBaseUrl: () => null, getToken: () => null, fetchImpl: f.impl }).get('/me')).rejects.toMatchObject({ kind: 'network' });
    expect(f.calls).toHaveLength(0);
  });

  test('the token is read fresh on every call (it changes after sign-in or sign-out)', async () => {
    const f = fakeFetch(() => ({ status: 200, body: {} }));
    let token: string | null = null;
    const c = createApiClient({ getBaseUrl: () => 'https://x.example.com', getToken: () => token, fetchImpl: f.impl });
    await c.get('/me');
    token = 'newtoken';
    await c.get('/me');
    expect((f.calls[0]?.init.headers as Record<string, string>).Authorization).toBeUndefined();
    expect((f.calls[1]?.init.headers as Record<string, string>).Authorization).toBe('Bearer newtoken');
  });
});
