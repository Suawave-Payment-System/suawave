import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { describe, it } from 'node:test';
import { SignatureVerificationError, constructWebhookEvent, verifyCallbackSignature, verifyWebhookSignature } from '../src/verify.js';
import { callbackFixture, weakKeyFixture, webhookFixture } from './fixtures.js';

function webhookInput(overrides: Record<string, unknown> = {}) {
  return {
    secret: webhookFixture.secret,
    headers: {
      'X-Gateway-Timestamp': webhookFixture.timestamp,
      'X-Gateway-Signature': `v1=${webhookFixture.signature}`,
    } as Record<string, string | string[] | undefined>,
    rawBody: webhookFixture.rawBody,
    nowMs: webhookFixture.nowMs,
    ...overrides,
  };
}

function reasonOf(action: () => unknown): string {
  try {
    action();
  } catch (error) {
    assert.ok(error instanceof SignatureVerificationError, 'expected SignatureVerificationError');
    return error.reason;
  }
  assert.fail('expected a rejection');
}

function sign(secret: string, timestamp: string, body: string): string {
  return createHmac('sha256', secret).update(`${timestamp}.${body}`).digest('hex');
}

describe('webhook signature', () => {
  it('accepts the known-good vector', () => {
    verifyWebhookSignature(webhookInput());
  });

  it('matches an independently computed HMAC', () => {
    assert.equal(sign(webhookFixture.secret, webhookFixture.timestamp, webhookFixture.rawBody), webhookFixture.signature);
  });

  it('accepts bytes and a Fetch Headers object', () => {
    const headers = new Headers({
      'x-gateway-timestamp': webhookFixture.timestamp,
      'x-gateway-signature': `v1=${webhookFixture.signature}`,
    });
    verifyWebhookSignature(webhookInput({ headers, rawBody: Buffer.from(webhookFixture.rawBody, 'utf8') }));
  });

  it('accepts lower case node headers and a rotation pair with the new secret second', () => {
    const headers = {
      'x-gateway-timestamp': webhookFixture.timestamp,
      'x-gateway-signature': `v1=${'0'.repeat(64)}, v1=${webhookFixture.signature}`,
    };
    verifyWebhookSignature(webhookInput({ headers }));
  });

  it('rejects a bad signature', () => {
    const headers = { 'X-Gateway-Timestamp': webhookFixture.timestamp, 'X-Gateway-Signature': `v1=${'a'.repeat(64)}` };
    assert.equal(reasonOf(() => verifyWebhookSignature(webhookInput({ headers }))), 'invalid_signature');
  });

  it('rejects a malformed signature value', () => {
    const headers = { 'X-Gateway-Timestamp': webhookFixture.timestamp, 'X-Gateway-Signature': 'v1=zz' };
    assert.equal(reasonOf(() => verifyWebhookSignature(webhookInput({ headers }))), 'invalid_signature');
  });

  it('rejects a signature over different bytes', () => {
    const tampered = webhookFixture.rawBody.replace('100.000000', '999.000000');
    assert.equal(reasonOf(() => verifyWebhookSignature(webhookInput({ rawBody: tampered }))), 'invalid_signature');
    const reserialized = JSON.stringify(JSON.parse(webhookFixture.rawBody), null, 2);
    assert.equal(reasonOf(() => verifyWebhookSignature(webhookInput({ rawBody: reserialized }))), 'invalid_signature');
  });

  it('rejects an empty, missing, non-string or short secret before computing anything', () => {
    for (const secret of ['', undefined, null, 42, 'short', 'a'.repeat(31)]) {
      assert.equal(reasonOf(() => verifyWebhookSignature(webhookInput({ secret }))), 'missing_secret');
      assert.equal(reasonOf(() => constructWebhookEvent(webhookInput({ secret }))), 'missing_secret');
    }
  });

  it('rejects an empty or missing public key', () => {
    for (const publicKeyPem of ['', '   ', undefined]) {
      assert.equal(reasonOf(() => verifyCallbackSignature({ publicKeyPem: publicKeyPem as unknown as string, signature: 'AAAA', signedData: 'x' })), 'invalid_public_key');
    }
  });

  it('rejects the wrong secret', () => {
    assert.equal(reasonOf(() => verifyWebhookSignature(webhookInput({ secret: 'f'.repeat(64) }))), 'invalid_signature');
  });

  it('rejects a missing signature header and a missing timestamp header', () => {
    assert.equal(reasonOf(() => verifyWebhookSignature(webhookInput({ headers: { 'X-Gateway-Timestamp': webhookFixture.timestamp } }))), 'missing_signature');
    assert.equal(reasonOf(() => verifyWebhookSignature(webhookInput({ headers: { 'X-Gateway-Signature': `v1=${webhookFixture.signature}` } }))), 'missing_timestamp');
  });

  it('rejects an unknown signature scheme', () => {
    const headers = { 'X-Gateway-Timestamp': webhookFixture.timestamp, 'X-Gateway-Signature': `v2=${webhookFixture.signature}` };
    assert.equal(reasonOf(() => verifyWebhookSignature(webhookInput({ headers }))), 'invalid_signature');
  });

  it('rejects a non numeric timestamp', () => {
    const headers = { 'X-Gateway-Timestamp': '17611344xx', 'X-Gateway-Signature': `v1=${webhookFixture.signature}` };
    assert.equal(reasonOf(() => verifyWebhookSignature(webhookInput({ headers }))), 'invalid_timestamp');
  });

  it('rejects a stale and a future timestamp but accepts the edge of the window', () => {
    assert.equal(reasonOf(() => verifyWebhookSignature(webhookInput({ nowMs: webhookFixture.nowMs + 301_000 }))), 'stale_timestamp');
    assert.equal(reasonOf(() => verifyWebhookSignature(webhookInput({ nowMs: webhookFixture.nowMs - 301_000 }))), 'stale_timestamp');
    verifyWebhookSignature(webhookInput({ nowMs: webhookFixture.nowMs + 300_000 }));
    verifyWebhookSignature(webhookInput({ nowMs: webhookFixture.nowMs - 300_000 }));
  });

  it('honours a custom tolerance', () => {
    assert.equal(reasonOf(() => verifyWebhookSignature(webhookInput({ nowMs: webhookFixture.nowMs + 61_000, toleranceSeconds: 60 }))), 'stale_timestamp');
  });

  it('constructWebhookEvent returns the parsed event only after verification', () => {
    const event = constructWebhookEvent(webhookInput());
    assert.equal(event.type, 'payment.confirmed');
    assert.equal(event.data.amount, '100.000000');
    assert.equal(event.data.received_amount, '100.000000');
    assert.equal(reasonOf(() => constructWebhookEvent(webhookInput({ rawBody: '{}' }))), 'invalid_signature');
  });

  it('constructWebhookEvent rejects a correctly signed body that is not an event', () => {
    const body = 'not json';
    const headers = { 'X-Gateway-Timestamp': webhookFixture.timestamp, 'X-Gateway-Signature': `v1=${sign(webhookFixture.secret, webhookFixture.timestamp, body)}` };
    assert.equal(reasonOf(() => constructWebhookEvent(webhookInput({ headers, rawBody: body }))), 'invalid_body');
  });
});

describe('public callback signature', () => {
  it('accepts the known-good signature over the full GET URL', () => {
    verifyCallbackSignature({ publicKeyPem: callbackFixture.publicKeyPem, signature: callbackFixture.urlSignature, signedData: callbackFixture.signedUrl });
  });

  it('accepts the known-good signature over the raw POST body, as string or bytes', () => {
    verifyCallbackSignature({ publicKeyPem: callbackFixture.publicKeyPem, signature: callbackFixture.bodySignature, signedData: callbackFixture.rawBody });
    verifyCallbackSignature({ publicKeyPem: callbackFixture.publicKeyPem, signature: callbackFixture.bodySignature, signedData: Buffer.from(callbackFixture.rawBody) });
  });

  it('rejects a bad signature', () => {
    const flipped = callbackFixture.urlSignature.slice(0, -6) + 'AAAAA=';
    assert.equal(reasonOf(() => verifyCallbackSignature({ publicKeyPem: callbackFixture.publicKeyPem, signature: flipped, signedData: callbackFixture.signedUrl })), 'invalid_signature');
    assert.equal(reasonOf(() => verifyCallbackSignature({ publicKeyPem: callbackFixture.publicKeyPem, signature: 'not base64 !!', signedData: callbackFixture.signedUrl })), 'invalid_signature');
  });

  it('rejects a signature over different bytes', () => {
    const otherUrl = callbackFixture.signedUrl.replace('order=1001', 'order=1002');
    assert.equal(reasonOf(() => verifyCallbackSignature({ publicKeyPem: callbackFixture.publicKeyPem, signature: callbackFixture.urlSignature, signedData: otherUrl })), 'invalid_signature');
    assert.equal(reasonOf(() => verifyCallbackSignature({ publicKeyPem: callbackFixture.publicKeyPem, signature: callbackFixture.bodySignature, signedData: callbackFixture.rawBody + ' ' })), 'invalid_signature');
  });

  it('rejects a missing signature header', () => {
    assert.equal(reasonOf(() => verifyCallbackSignature({ publicKeyPem: callbackFixture.publicKeyPem, signature: undefined, signedData: callbackFixture.signedUrl })), 'missing_signature');
    assert.equal(reasonOf(() => verifyCallbackSignature({ publicKeyPem: callbackFixture.publicKeyPem, signature: '', signedData: callbackFixture.signedUrl })), 'missing_signature');
  });

  it('rejects an unparseable key and a key shorter than 2048 bits', () => {
    assert.equal(reasonOf(() => verifyCallbackSignature({ publicKeyPem: 'garbage', signature: callbackFixture.urlSignature, signedData: callbackFixture.signedUrl })), 'invalid_public_key');
    assert.equal(reasonOf(() => verifyCallbackSignature({ publicKeyPem: weakKeyFixture.publicKeyPem, signature: weakKeyFixture.urlSignature, signedData: callbackFixture.signedUrl })), 'invalid_public_key');
  });
});
