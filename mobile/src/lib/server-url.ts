/**
 * Turns whatever the person typed into the server address into a clean base URL such as
 * "https://tracker.example.com". Rules:
 *  - release builds accept https only (the server has HTTPS; plain http is for development);
 *  - a missing scheme means https, except a bare IP / localhost / *.local in development, which means http;
 *  - no passwords in the address, no paths, no query strings (a pasted "/login" or "/api/v1" is dropped).
 */

export type ServerUrlResult = { ok: true; url: string } | { ok: false; error: string };

const LOCAL_HOST = /^(localhost|\d{1,3}(\.\d{1,3}){3}|[^./]+\.local)$/i;

export function normalizeServerUrl(input: string, allowHttp: boolean): ServerUrlResult {
  let text = input.trim();
  if (text === '') return { ok: false, error: 'Enter the server address, for example https://tracker.example.com' };
  if (/\s/.test(text)) return { ok: false, error: 'The server address cannot contain spaces.' };

  if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(text)) {
    const host = text.split(/[/:?#]/)[0] ?? '';
    text = `${allowHttp && LOCAL_HOST.test(host) ? 'http' : 'https'}://${text}`;
  }

  let parsed: URL;
  try {
    parsed = new URL(text);
  } catch {
    return { ok: false, error: 'That does not look like a web address.' };
  }

  if (parsed.protocol !== 'https:' && parsed.protocol !== 'http:') {
    return { ok: false, error: 'The address must start with https://' };
  }
  if (parsed.protocol === 'http:' && !allowHttp) {
    return { ok: false, error: 'This app only connects over https:// (secure). Use your server\'s https address.' };
  }
  if (parsed.username || parsed.password) {
    return { ok: false, error: 'Do not put a user name or password in the address.' };
  }
  if (!parsed.hostname || (parsed.hostname.indexOf('.') === -1 && !/^localhost$/i.test(parsed.hostname))) {
    return { ok: false, error: 'That address needs a full domain name, for example tracker.example.com' };
  }

  // origin = scheme + host + port; everything after is dropped on purpose.
  return { ok: true, url: parsed.origin };
}
