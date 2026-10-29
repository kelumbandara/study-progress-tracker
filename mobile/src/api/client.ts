import { API_PREFIX, REQUEST_TIMEOUT_MS } from '@/config';
import { ApiError } from './errors';

export interface ApiClientOptions {
  /** The server's base URL (no trailing slash), or null before the person has chosen one. */
  getBaseUrl: () => string | null;
  /** The bearer token, or null when signed out. */
  getToken: () => string | null;
  /** Called once when the server says the token is no longer valid (HTTP 401). */
  onUnauthorized?: () => void;
  fetchImpl?: typeof fetch;
  timeoutMs?: number;
}

export interface RequestOptions {
  /** false for the sign-in call (no token yet, and a 401 must not sign anyone out). */
  auth?: boolean;
}

export interface ApiClient {
  get<T>(path: string, query?: Record<string, string | number | undefined>): Promise<T>;
  post<T>(path: string, body?: unknown, options?: RequestOptions): Promise<T>;
  put<T>(path: string, body?: unknown): Promise<T>;
}

type ErrorBody = { message?: unknown; errors?: unknown };

function readFieldErrors(body: ErrorBody | null): Record<string, string[]> {
  const raw = body?.errors;
  if (!raw || typeof raw !== 'object') return {};
  const out: Record<string, string[]> = {};
  for (const [field, messages] of Object.entries(raw as Record<string, unknown>)) {
    if (Array.isArray(messages)) out[field] = messages.filter((m): m is string => typeof m === 'string');
  }
  return out;
}

export function createApiClient(opts: ApiClientOptions): ApiClient {
  const doFetch = opts.fetchImpl ?? fetch;
  const timeoutMs = opts.timeoutMs ?? REQUEST_TIMEOUT_MS;

  async function request<T>(method: string, path: string, body?: unknown, query?: Record<string, string | number | undefined>, auth = true): Promise<T> {
    const base = opts.getBaseUrl();
    if (!base) throw new ApiError('network', 'No server address is set. Sign in again.');

    let url = `${base}${API_PREFIX}${path}`;
    if (query) {
      const qs = Object.entries(query)
        .filter(([, v]) => v !== undefined && v !== '')
        .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`)
        .join('&');
      if (qs) url += `?${qs}`;
    }

    const headers: Record<string, string> = { Accept: 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    const token = opts.getToken();
    if (auth && token) headers.Authorization = `Bearer ${token}`;

    const controller = new AbortController();
    let timedOut = false;
    const timer = setTimeout(() => {
      timedOut = true;
      controller.abort();
    }, timeoutMs);

    let response: Response;
    try {
      response = await doFetch(url, { method, headers, body: body === undefined ? undefined : JSON.stringify(body), signal: controller.signal });
    } catch {
      throw timedOut
        ? new ApiError('timeout', 'The server took too long to answer. Please try again.')
        : new ApiError('network', 'Cannot reach the server. Check your internet connection and the server address.');
    } finally {
      clearTimeout(timer);
    }

    const text = await response.text();
    let json: unknown = null;
    try {
      json = text ? JSON.parse(text) : null;
    } catch {
      json = null; // e.g. an HTML error page from a proxy
    }

    if (response.ok) {
      if (json === null && text) throw new ApiError('server', 'The server sent an unexpected reply. Please try again.', response.status);
      return json as T;
    }

    const err = (json && typeof json === 'object' ? (json as ErrorBody) : null) ?? null;
    const serverMessage = typeof err?.message === 'string' ? err.message : undefined;

    switch (response.status) {
      case 401:
        if (auth) opts.onUnauthorized?.();
        throw new ApiError('unauthorized', 'Your session has ended. Please sign in again.', 401);
      case 404:
        throw new ApiError('not_found', serverMessage ?? 'That item was not found.', 404);
      case 422: {
        const fieldErrors = readFieldErrors(err);
        const first = Object.values(fieldErrors).flat()[0];
        throw new ApiError('validation', first ?? serverMessage ?? 'Some of the details are not valid.', 422, fieldErrors);
      }
      case 429:
        throw new ApiError('rate_limited', 'Too many requests. Please wait a moment and try again.', 429);
      default:
        throw new ApiError('server', 'The server had a problem. Please try again in a moment.', response.status);
    }
  }

  return {
    get: (path, query) => request('GET', path, undefined, query),
    post: (path, body, options) => request('POST', path, body ?? {}, undefined, options?.auth ?? true),
    put: (path, body) => request('PUT', path, body ?? {}),
  };
}
