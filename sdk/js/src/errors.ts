import type { ErrorCode } from './types.js';

export class GatewayError extends Error {
  constructor(message: string, options?: { cause?: unknown }) {
    super(message, options);
    this.name = new.target.name;
  }
}

export interface ApiErrorDetails {
  status: number;
  code: ErrorCode | undefined;
  message: string;
  requestId: string | undefined;
  retryAfterSeconds: number | undefined;
  body: unknown;
}

export class ApiError extends GatewayError {
  readonly status: number;
  readonly code: ErrorCode | undefined;
  readonly requestId: string | undefined;
  readonly retryAfterSeconds: number | undefined;
  readonly body: unknown;

  constructor(details: ApiErrorDetails) {
    super(details.message);
    this.status = details.status;
    this.code = details.code;
    this.requestId = details.requestId;
    this.retryAfterSeconds = details.retryAfterSeconds;
    this.body = details.body;
  }
}

export class RateLimitError extends ApiError {}

export class NetworkError extends GatewayError {
  readonly aborted: boolean;

  constructor(message: string, aborted: boolean, cause: unknown) {
    super(message, { cause });
    this.aborted = aborted;
  }
}

export class MalformedResponseError extends GatewayError {
  readonly status: number;
  readonly bodyText: string;

  constructor(message: string, status: number, bodyText: string) {
    super(message);
    this.status = status;
    this.bodyText = bodyText;
  }
}

export class InvalidAmountError extends GatewayError {}
