/**
 * The API's single error envelope, mirrored on the client.
 *
 * Every failure from the SAKA API arrives in the same shape:
 *
 *   { "error": { "code", "message", "details", "request_id" } }
 *
 * with `errors` additionally present on a 422. Modelling it once here means no
 * call site guesses at the body, and `request_id` survives to the UI — which is
 * the only thing that makes an administrator's bug report joinable to a log.
 */

export type ApiErrorCode =
  | "VALIDATION_FAILED"
  | "UNAUTHENTICATED"
  | "FORBIDDEN"
  | "NOT_FOUND"
  | "CONFLICT"
  | "INVALID_STATE_TRANSITION"
  | "RATE_LIMITED"
  | "INVALID_CREDENTIALS"
  | "ACCOUNT_SUSPENDED"
  | "ACCOUNT_BANNED"
  | "METHOD_NOT_ALLOWED"
  | "SERVER_ERROR"
  | (string & {});

export type FieldErrors = Record<string, string[]>;

export class ApiError extends Error {
  readonly status: number;
  readonly code: ApiErrorCode;
  readonly details: Record<string, unknown>;
  readonly requestId: string | null;
  readonly fieldErrors: FieldErrors;

  constructor(init: {
    status: number;
    code: ApiErrorCode;
    message: string;
    details?: Record<string, unknown>;
    requestId?: string | null;
    fieldErrors?: FieldErrors;
  }) {
    super(init.message);
    this.name = "ApiError";
    this.status = init.status;
    this.code = init.code;
    this.details = init.details ?? {};
    this.requestId = init.requestId ?? null;
    this.fieldErrors = init.fieldErrors ?? {};
  }

  get isNotFound() { return this.status === 404; }
  get isUnauthenticated() { return this.status === 401; }
  get isForbidden() { return this.status === 403; }
  get isValidation() { return this.status === 422; }
  get isConflict() { return this.status === 409; }

  /**
   * Retrying a 4xx re-sends a request the server rejected on its merits. Only
   * transport failures and 5xx are worth another attempt; 429 is excluded
   * because the server has explicitly asked us to slow down.
   */
  get isRetryable() { return this.status === 0 || this.status >= 500; }

  fieldError(field: string): string | undefined {
    return this.fieldErrors[field]?.[0];
  }

  /** Every field message, flattened — for forms with no per-field slots. */
  allFieldMessages(): string[] {
    return Object.values(this.fieldErrors).flat();
  }
}

export function networkError(cause: unknown): ApiError {
  return new ApiError({
    status: 0,
    code: "NETWORK_ERROR",
    message: "Could not reach the server. Check your connection and try again.",
    details: { cause: String(cause) },
  });
}

export async function errorFromResponse(response: Response): Promise<ApiError> {
  const requestId = response.headers.get("X-Request-Id");
  let body: unknown = null;

  try {
    body = await response.json();
  } catch {
    // A proxy 502 or an HTML error page is exactly when a usable error object
    // matters most, so a non-JSON body must not throw from inside a render.
  }

  const envelope =
    body && typeof body === "object" && "error" in body
      ? ((body as { error: Record<string, unknown> }).error ?? {})
      : {};

  const fieldErrors =
    body && typeof body === "object" && "errors" in body
      ? ((body as { errors: FieldErrors }).errors ?? {})
      : {};

  return new ApiError({
    status: response.status,
    code: (envelope.code as string) ?? fallbackCode(response.status),
    message: (envelope.message as string) ?? fallbackMessage(response.status),
    details: (envelope.details as Record<string, unknown>) ?? {},
    requestId: (envelope.request_id as string) ?? requestId,
    fieldErrors,
  });
}

function fallbackCode(status: number): ApiErrorCode {
  if (status === 401) return "UNAUTHENTICATED";
  if (status === 403) return "FORBIDDEN";
  if (status === 404) return "NOT_FOUND";
  if (status === 405) return "METHOD_NOT_ALLOWED";
  if (status === 409) return "CONFLICT";
  if (status === 422) return "VALIDATION_FAILED";
  if (status === 429) return "RATE_LIMITED";
  return "SERVER_ERROR";
}

function fallbackMessage(status: number): string {
  if (status === 401) return "Your session has ended. Please sign in again.";
  if (status === 403) return "You do not have permission to do that.";
  if (status === 404) return "That could not be found.";
  if (status === 409) return "That conflicts with the current state.";
  if (status === 429) return "Too many requests. Please wait a moment.";
  return "Something went wrong. Please try again.";
}
