// Vendored axios subset of the shared @vctrs/plugin-ui client-fetch kit
// (core packages/plugin-ui/src/client.ts). The kit is not yet npm-published, so an
// external plugin vendors it. useApiQuery is intentionally omitted — it imports React,
// which must come from host.React in the ESM module model (a bundled `import react`
// would be unresolvable and risk a second React instance). See the extraction spec §5.
import axios, { type AxiosInstance } from 'axios';

/**
 * Canonical /api/v1 response envelope — mirrors app/Support/ApiResponse.php.
 *
 * NOTE: this client requires that canonical envelope; unwrap() throws on any 2xx
 * whose `status !== 'success'`. Not every existing /api/v1/* endpoint emits it yet —
 * context-hub's RecordQueryController (and its other read controllers) still return a
 * raw `{data: …}` shape and must be migrated to ApiResponse before being wired to this
 * kit. See the "envelope-shape contract gap" section in
 * docs/superpowers/specs/2026-07-14-extraction-foundation-design.md.
 */
export interface ApiEnvelope<T> {
  traceId: string;
  data: T | null;
  status: 'success' | 'error';
  error?: string;
  errors?: Record<string, string[]>;
}

export class ApiClientError extends Error {
  readonly status: number;
  readonly traceId: string;
  readonly errors?: Record<string, string[]>;

  constructor(message: string, status: number, traceId: string, errors?: Record<string, string[]>) {
    super(message);
    this.name = 'ApiClientError';
    this.status = status;
    this.traceId = traceId;
    this.errors = errors;
  }
}

/**
 * A same-origin, session-cookie-authenticated axios client for the session-authed
 * /api/v1 surface (see bootstrap/app.php's `session-api` middleware group). Cookies
 * (session + XSRF-TOKEN) are sent automatically for same-origin requests; axios's
 * default `xsrfCookieName`/`xsrfHeaderName` already read XSRF-TOKEN → X-XSRF-TOKEN,
 * matching how the app's Inertia pages already authenticate mutating requests.
 */
export function createApiClient(baseURL = '/api/v1'): AxiosInstance {
  return axios.create({ baseURL, withCredentials: true });
}

export const apiClient = createApiClient();

async function unwrap<T>(request: Promise<{ data: ApiEnvelope<T> }>): Promise<T> {
  try {
    const response = await request;
    const envelope = response.data;

    if (envelope.status !== 'success') {
      throw new ApiClientError(envelope.error ?? 'Request failed', 200, envelope.traceId, envelope.errors);
    }

    return envelope.data as T;
  } catch (err) {
    if (err instanceof ApiClientError) {
      throw err;
    }

    if (err && typeof err === 'object' && 'response' in err && (err as { response?: unknown }).response) {
      const response = (err as { response: { status: number; data?: ApiEnvelope<T> } }).response;
      const envelope = response.data;

      throw new ApiClientError(
        envelope?.error ?? (err as Error).message,
        response.status,
        envelope?.traceId ?? '',
        envelope?.errors,
      );
    }

    throw err;
  }
}

export function apiGet<T>(path: string, client: AxiosInstance = apiClient): Promise<T> {
  return unwrap<T>(client.get(path));
}
