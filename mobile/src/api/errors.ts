/** What went wrong, in terms the screens can act on. */
export type ApiErrorKind =
  | 'network'       // could not reach the server at all
  | 'timeout'       // the server did not answer in time
  | 'unauthorized'  // 401: not signed in / token revoked or expired
  | 'validation'    // 422: a field or rule was refused (the text is shown to the person)
  | 'rate_limited'  // 429
  | 'not_found'     // 404
  | 'server';       // 5xx or a reply that is not the API's JSON

export class ApiError extends Error {
  readonly kind: ApiErrorKind;
  readonly status: number;
  /** Field name -> messages, exactly as the server sent them (for 422). */
  readonly fieldErrors: Record<string, string[]>;

  constructor(kind: ApiErrorKind, message: string, status = 0, fieldErrors: Record<string, string[]> = {}) {
    super(message);
    this.name = 'ApiError';
    this.kind = kind;
    this.status = status;
    this.fieldErrors = fieldErrors;
  }

  /** First message for a field, or for any field whose name starts with the prefix (e.g. "minutes"). */
  fieldMessage(field: string): string | undefined {
    const exact = this.fieldErrors[field]?.[0];
    if (exact) return exact;
    const key = Object.keys(this.fieldErrors).find((k) => k.startsWith(`${field}.`));
    return key ? this.fieldErrors[key]?.[0] : undefined;
  }

  /** Problems worth offering a "Try again" button for. */
  get isRetryable(): boolean {
    return this.kind === 'network' || this.kind === 'timeout' || this.kind === 'server' || this.kind === 'rate_limited';
  }
}

export function errorMessage(e: unknown): string {
  return e instanceof Error ? e.message : 'Something went wrong. Please try again.';
}
