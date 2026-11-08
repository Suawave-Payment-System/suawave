import { createHmac, createPublicKey, timingSafeEqual, verify } from 'node:crypto';
import type { WebhookEvent } from './types.js';

export const defaultToleranceSeconds = 300;
export const minimumRsaModulusBits = 2048;
export const minimumSecretLength = 32;

export type VerificationFailureReason =
  | 'missing_timestamp'
  | 'invalid_timestamp'
  | 'stale_timestamp'
  | 'missing_signature'
  | 'missing_secret'
  | 'invalid_signature'
  | 'invalid_public_key'
  | 'invalid_body';

export class SignatureVerificationError extends Error {
  readonly reason: VerificationFailureReason;

  constructor(reason: VerificationFailureReason, message: string) {
    super(message);
    this.name = 'SignatureVerificationError';
    this.reason = reason;
  }
}

export type HeaderSource =
  | { get(name: string): string | null }
  | Record<string, string | string[] | undefined>;

export interface VerifyWebhookInput {
  secret: string;
  headers: HeaderSource;
  rawBody: string | Uint8Array;
  nowMs?: number;
  toleranceSeconds?: number;
}

function readHeader(headers: HeaderSource, name: string): string | undefined {
  if ('get' in headers && typeof headers.get === 'function') {
    return headers.get(name) ?? undefined;
  }
  const record = headers as Record<string, string | string[] | undefined>;
  for (const key of Object.keys(record)) {
    if (key.toLowerCase() === name) {
      const value = record[key];
      return Array.isArray(value) ? value.join(',') : value;
    }
  }
  return undefined;
}

function toBuffer(data: string | Uint8Array): Buffer {
  return typeof data === 'string' ? Buffer.from(data, 'utf8') : Buffer.from(data.buffer, data.byteOffset, data.byteLength);
}

export function verifyWebhookSignature(input: VerifyWebhookInput): void {
  if (typeof input.secret !== 'string' || input.secret.length < minimumSecretLength) {
    throw new SignatureVerificationError('missing_secret', 'webhook secret is missing or too short');
  }
  const timestamp = readHeader(input.headers, 'x-gateway-timestamp');
  if (timestamp === undefined || timestamp === '') {
    throw new SignatureVerificationError('missing_timestamp', 'X-Gateway-Timestamp header is missing');
  }
  if (!/^[0-9]+$/.test(timestamp)) {
    throw new SignatureVerificationError('invalid_timestamp', 'X-Gateway-Timestamp is not a unix time in seconds');
  }
  const header = readHeader(input.headers, 'x-gateway-signature');
  if (header === undefined || header.trim() === '') {
    throw new SignatureVerificationError('missing_signature', 'X-Gateway-Signature header is missing');
  }
  const tolerance = input.toleranceSeconds ?? defaultToleranceSeconds;
  const nowSeconds = Math.floor((input.nowMs ?? Date.now()) / 1000);
  if (Math.abs(nowSeconds - Number(timestamp)) > tolerance) {
    throw new SignatureVerificationError('stale_timestamp', 'X-Gateway-Timestamp is outside the tolerance window');
  }

  const expected = createHmac('sha256', input.secret)
    .update(`${timestamp}.`)
    .update(toBuffer(input.rawBody))
    .digest();

  let matched = false;
  for (const part of header.split(',')) {
    const separator = part.indexOf('=');
    if (separator < 0) {
      continue;
    }
    const scheme = part.slice(0, separator).trim();
    const value = part.slice(separator + 1).trim();
    if (scheme !== 'v1' || !/^[0-9a-fA-F]{64}$/.test(value)) {
      continue;
    }
    if (timingSafeEqual(Buffer.from(value, 'hex'), expected)) {
      matched = true;
    }
  }
  if (!matched) {
    throw new SignatureVerificationError('invalid_signature', 'no X-Gateway-Signature entry matches');
  }
}

export function constructWebhookEvent(input: VerifyWebhookInput): WebhookEvent {
  verifyWebhookSignature(input);
  let parsed: unknown;
  try {
    parsed = JSON.parse(toBuffer(input.rawBody).toString('utf8'));
  } catch {
    throw new SignatureVerificationError('invalid_body', 'verified body is not valid JSON');
  }
  if (typeof parsed !== 'object' || parsed === null || typeof (parsed as { id?: unknown }).id !== 'string') {
    throw new SignatureVerificationError('invalid_body', 'verified body is not a webhook event');
  }
  return parsed as WebhookEvent;
}

export interface VerifyCallbackInput {
  publicKeyPem: string;
  signature: string | undefined;
  signedData: string | Uint8Array;
}

export function verifyCallbackSignature(input: VerifyCallbackInput): void {
  if (typeof input.publicKeyPem !== 'string' || input.publicKeyPem.trim() === '') {
    throw new SignatureVerificationError('invalid_public_key', 'public key is missing');
  }
  if (input.signature === undefined || input.signature.trim() === '') {
    throw new SignatureVerificationError('missing_signature', 'x-ca-signature header is missing');
  }
  let key;
  try {
    key = createPublicKey(input.publicKeyPem);
  } catch {
    throw new SignatureVerificationError('invalid_public_key', 'public key is not a valid PEM public key');
  }
  const bits = key.asymmetricKeyDetails?.modulusLength ?? 0;
  if (key.asymmetricKeyType !== 'rsa' || bits < minimumRsaModulusBits) {
    throw new SignatureVerificationError('invalid_public_key', `public key must be RSA with at least ${minimumRsaModulusBits} bits`);
  }
  const signature = Buffer.from(input.signature.trim(), 'base64');
  const valid = signature.length > 0 && verify('sha256', toBuffer(input.signedData), key, signature);
  if (!valid) {
    throw new SignatureVerificationError('invalid_signature', 'x-ca-signature does not match the signed data');
  }
}
