import { assertDecimalString } from './amount.js';
import { ApiError, GatewayError, MalformedResponseError, NetworkError, RateLimitError } from './errors.js';
import type {
  CreatePaymentOptions,
  CreatePaymentRequest,
  ErrorCode,
  FeeList,
  ListPaymentsParams,
  NetworkList,
  Payment,
  PaymentPage,
  PublicConvertParams,
  PublicConvertResponse,
  PublicCreateParams,
  PublicCreateResponse,
  PublicEstimateResponse,
  PublicInfoResponse,
  PublicKeyResponse,
  PublicLogsResponse,
  PublicQrCodeParams,
  PublicQrCodeResponse,
  PublicTickerConvertParams,
  RequestOptions,
} from './types.js';

export type FetchLike = (input: string, init?: RequestInit) => Promise<Response>;

export interface GatewayClientOptions {
  baseUrl?: string;
  apiKey?: string;
  fetch?: FetchLike;
  timeoutMs?: number;
}

interface RequestSpec {
  method: 'GET' | 'POST';
  path: string;
  query?: Record<string, string | undefined>;
  body?: unknown;
  idempotencyKey?: string;
  authenticated: boolean;
  publicEnvelope: boolean;
  signal?: AbortSignal | undefined;
}

const defaultTimeoutMs = 30_000;

export function generateIdempotencyKey(): string {
  const webCrypto = globalThis.crypto;
  if (typeof webCrypto?.randomUUID === 'function') {
    return webCrypto.randomUUID();
  }
  const bytes = webCrypto.getRandomValues(new Uint8Array(16));
  return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
}

function parseRetryAfter(header: string | null): number | undefined {
  if (header === null) {
    return undefined;
  }
  const trimmed = header.trim();
  if (/^[0-9]+$/.test(trimmed)) {
    return Number(trimmed);
  }
  const date = Date.parse(trimmed);
  if (Number.isNaN(date)) {
    return undefined;
  }
  return Math.max(0, Math.ceil((date - Date.now()) / 1000));
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function parseJson(text: string): unknown {
  try {
    return JSON.parse(text);
  } catch {
    return undefined;
  }
}

function tickerPath(ticker: string): string {
  const segments = ticker.split('/');
  if (ticker === '' || segments.some((segment) => segment === '')) {
    throw new GatewayError(`ticker must look like "trc20/usdt", got "${ticker}"`);
  }
  return segments.map(encodeURIComponent).join('/');
}

function flag(value: boolean | undefined): string | undefined {
  if (value === undefined) {
    return undefined;
  }
  return value ? '1' : '0';
}

export class GatewayClient {
  private readonly baseUrl: string;
  private readonly apiKey: string | undefined;
  private readonly fetchImplementation: FetchLike;
  private readonly timeoutMs: number;

  constructor(options: GatewayClientOptions = {}) {
    this.baseUrl = (options.baseUrl || 'https://api.suawave.com').replace(/\/+$/, '');
    this.apiKey = options.apiKey;
    this.fetchImplementation = options.fetch ?? ((input, init) => globalThis.fetch(input, init));
    this.timeoutMs = options.timeoutMs ?? defaultTimeoutMs;
  }

  async createPayment(request: CreatePaymentRequest, options: CreatePaymentOptions = {}): Promise<Payment> {
    assertDecimalString(request.amount);
    return this.send<Payment>({
      method: 'POST',
      path: '/v1/payments',
      body: request,
      idempotencyKey: options.idempotencyKey ?? generateIdempotencyKey(),
      authenticated: true,
      publicEnvelope: false,
      signal: options.signal,
    });
  }

  async getPayment(id: string, options: RequestOptions = {}): Promise<Payment> {
    if (id === '') {
      throw new GatewayError('payment id is required');
    }
    return this.send<Payment>({
      method: 'GET',
      path: `/v1/payments/${encodeURIComponent(id)}`,
      authenticated: true,
      publicEnvelope: false,
      signal: options.signal,
    });
  }

  async getPaymentByExternalId(externalId: string, options: RequestOptions = {}): Promise<Payment> {
    if (externalId === '') {
      throw new GatewayError('external_id is required');
    }
    return this.send<Payment>({
      method: 'GET',
      path: '/v1/payments',
      query: { external_id: externalId },
      authenticated: true,
      publicEnvelope: false,
      signal: options.signal,
    });
  }

  async listPayments(params: ListPaymentsParams = {}, options: RequestOptions = {}): Promise<PaymentPage> {
    return this.send<PaymentPage>({
      method: 'GET',
      path: '/v1/payments',
      query: {
        limit: params.limit === undefined ? undefined : String(params.limit),
        cursor: params.cursor,
        status: params.status,
      },
      authenticated: true,
      publicEnvelope: false,
      signal: options.signal,
    });
  }

  async *iteratePayments(params: Omit<ListPaymentsParams, 'cursor'> = {}, options: RequestOptions = {}): AsyncGenerator<Payment> {
    let cursor: string | undefined;
    for (;;) {
      const page = await this.listPayments(cursor === undefined ? params : { ...params, cursor }, options);
      yield* page.data;
      if (!page.has_more || page.next_cursor === null) {
        return;
      }
      cursor = page.next_cursor;
    }
  }

  async listNetworks(options: RequestOptions = {}): Promise<NetworkList> {
    return this.send<NetworkList>({
      method: 'GET',
      path: '/v1/networks',
      authenticated: true,
      publicEnvelope: false,
      signal: options.signal,
    });
  }

  async listFees(options: RequestOptions = {}): Promise<FeeList> {
    return this.send<FeeList>({
      method: 'GET',
      path: '/v1/fees',
      authenticated: true,
      publicEnvelope: false,
      signal: options.signal,
    });
  }

  async publicCreate(params: PublicCreateParams, options: RequestOptions = {}): Promise<PublicCreateResponse> {
    return this.sendPublic<PublicCreateResponse>(`/${tickerPath(params.ticker)}/create/`, {
      callback: params.callback,
      address: params.address,
      pending: flag(params.pending),
      post: flag(params.post),
      json: flag(params.json),
      convert: flag(params.convert),
    }, options);
  }

  async publicInfo(ticker: string, options: RequestOptions = {}): Promise<PublicInfoResponse> {
    return this.sendPublic<PublicInfoResponse>(`/${tickerPath(ticker)}/info/`, {}, options);
  }

  async publicLogs(ticker: string, callback: string, options: RequestOptions = {}): Promise<PublicLogsResponse> {
    return this.sendPublic<PublicLogsResponse>(`/${tickerPath(ticker)}/logs/`, { callback }, options);
  }

  async publicEstimate(ticker: string, options: RequestOptions = {}): Promise<PublicEstimateResponse> {
    return this.sendPublic<PublicEstimateResponse>(`/${tickerPath(ticker)}/estimate/`, { addresses: '1' }, options);
  }

  async publicQrCode(params: PublicQrCodeParams, options: RequestOptions = {}): Promise<PublicQrCodeResponse> {
    if (params.value !== undefined) {
      assertDecimalString(params.value, 'value');
    }
    return this.sendPublic<PublicQrCodeResponse>(`/${tickerPath(params.ticker)}/qrcode/`, {
      address: params.address,
      value: params.value,
      size: params.size === undefined ? undefined : String(params.size),
    }, options);
  }

  async publicConvert(params: PublicConvertParams, options: RequestOptions = {}): Promise<PublicConvertResponse> {
    assertDecimalString(params.value, 'value');
    return this.sendPublic<PublicConvertResponse>('/convert/', {
      value: params.value,
      from: params.from,
      to: params.to,
    }, options);
  }

  async publicTickerConvert(params: PublicTickerConvertParams, options: RequestOptions = {}): Promise<PublicConvertResponse> {
    assertDecimalString(params.value, 'value');
    return this.sendPublic<PublicConvertResponse>(`/${tickerPath(params.ticker)}/convert/`, {
      value: params.value,
      from: params.from,
    }, options);
  }

  async publicCallbackKey(options: RequestOptions = {}): Promise<PublicKeyResponse> {
    return this.sendPublic<PublicKeyResponse>('/pubkey/', {}, options);
  }

  private sendPublic<T>(path: string, query: Record<string, string | undefined>, options: RequestOptions): Promise<T> {
    return this.send<T>({
      method: 'GET',
      path,
      query,
      authenticated: false,
      publicEnvelope: true,
      signal: options.signal,
    });
  }

  private async send<T>(spec: RequestSpec): Promise<T> {
    if (spec.authenticated && !this.apiKey) {
      throw new GatewayError('apiKey is required for /v1 operations');
    }
    const url = this.buildUrl(spec);
    const headers: Record<string, string> = { Accept: 'application/json' };
    if (spec.authenticated) {
      headers['Authorization'] = `Bearer ${this.apiKey}`;
    }
    if (spec.body !== undefined) {
      headers['Content-Type'] = 'application/json';
    }
    if (spec.idempotencyKey !== undefined) {
      headers['Idempotency-Key'] = spec.idempotencyKey;
    }
    const init: RequestInit = { method: spec.method, headers };
    if (spec.body !== undefined) {
      init.body = JSON.stringify(spec.body);
    }

    const { text, response } = await this.exchange(url, init, spec.signal);
    const parsed = parseJson(text);

    if (!response.ok) {
      throw this.buildApiError(response, text, parsed);
    }
    if (!isRecord(parsed)) {
      throw new MalformedResponseError('response body is not a JSON object', response.status, text);
    }
    if (spec.publicEnvelope && parsed['status'] !== 'success') {
      throw new MalformedResponseError('public response did not carry status "success"', response.status, text);
    }
    return parsed as T;
  }

  private buildUrl(spec: RequestSpec): string {
    const search = new URLSearchParams();
    for (const [name, value] of Object.entries(spec.query ?? {})) {
      if (value !== undefined) {
        search.append(name, value);
      }
    }
    const queryString = search.toString();
    return `${this.baseUrl}${spec.path}${queryString === '' ? '' : `?${queryString}`}`;
  }

  private async exchange(url: string, init: RequestInit, callerSignal: AbortSignal | undefined): Promise<{ text: string; response: Response }> {
    const controller = new AbortController();
    let timedOut = false;
    const timer = setTimeout(() => {
      timedOut = true;
      controller.abort();
    }, this.timeoutMs);
    const forwardAbort = () => controller.abort();
    if (callerSignal?.aborted) {
      controller.abort();
    }
    callerSignal?.addEventListener('abort', forwardAbort, { once: true });
    try {
      const response = await this.fetchImplementation(url, { ...init, signal: controller.signal });
      const text = await response.text();
      return { text, response };
    } catch (cause) {
      const aborted = controller.signal.aborted;
      let reason = 'request failed';
      if (timedOut) {
        reason = `request timed out after ${this.timeoutMs} ms`;
      } else if (aborted) {
        reason = 'request aborted';
      } else if (cause instanceof Error) {
        reason = `request failed: ${cause.message}`;
      }
      throw new NetworkError(reason, aborted, cause);
    } finally {
      clearTimeout(timer);
      callerSignal?.removeEventListener('abort', forwardAbort);
    }
  }

  private buildApiError(response: Response, text: string, parsed: unknown): ApiError {
    let code: ErrorCode | undefined;
    let message = text.trim().slice(0, 500) || response.statusText || `HTTP ${response.status}`;
    let requestId = response.headers.get('X-Request-Id') ?? undefined;
    if (isRecord(parsed)) {
      const envelope = parsed['error'];
      if (isRecord(envelope)) {
        if (typeof envelope['code'] === 'string') {
          code = envelope['code'] as ErrorCode;
        }
        if (typeof envelope['message'] === 'string') {
          message = envelope['message'];
        }
        if (typeof envelope['request_id'] === 'string') {
          requestId = envelope['request_id'];
        }
      } else if (typeof envelope === 'string') {
        message = envelope;
      }
    }
    const details = {
      status: response.status,
      code,
      message,
      requestId,
      retryAfterSeconds: parseRetryAfter(response.headers.get('Retry-After')),
      body: parsed ?? text,
    };
    return response.status === 429 ? new RateLimitError(details) : new ApiError(details);
  }
}
