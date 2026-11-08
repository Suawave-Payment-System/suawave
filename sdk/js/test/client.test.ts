import assert from 'node:assert/strict';
import { afterEach, describe, it } from 'node:test';
import {
  ApiError,
  GatewayClient,
  GatewayError,
  InvalidAmountError,
  MalformedResponseError,
  NetworkError,
  RateLimitError,
} from '../src/index.js';
import { paymentFixture, sendJson, startServer } from './server.js';
import type { Responder, TestServer } from './server.js';

const servers: TestServer[] = [];

async function clientFor(responder: Responder, apiKey: string | null = 'key_test', timeoutMs?: number) {
  const server = await startServer(responder);
  servers.push(server);
  const options = { baseUrl: server.baseUrl, ...(apiKey === null ? {} : { apiKey }), ...(timeoutMs === undefined ? {} : { timeoutMs }) };
  return { server, client: new GatewayClient(options) };
}

afterEach(async () => {
  await Promise.all(servers.splice(0).map((server) => server.close()));
});

describe('merchant operations', () => {
  it('createPayment sends the amount as a string with auth and idempotency headers', async () => {
    const { server, client } = await clientFor((_, response) => sendJson(response, 201, paymentFixture));
    const payment = await client.createPayment({ external_id: 'order_123', network: 'TRON', asset: 'USDT', amount: '100.500000' });
    assert.equal(payment.id, paymentFixture.id);
    assert.equal(payment.amount, '100.500000');
    const request = server.requests[0]!;
    assert.equal(request.method, 'POST');
    assert.equal(request.url, '/v1/payments');
    assert.equal(request.headers['authorization'], 'Bearer key_test');
    assert.match(String(request.headers['idempotency-key']), /^[0-9a-f-]{16,}$/);
    assert.equal(request.body, '{"external_id":"order_123","network":"TRON","asset":"USDT","amount":"100.500000"}');
  });

  it('createPayment reuses a caller supplied idempotency key', async () => {
    const { server, client } = await clientFor((_, response) => sendJson(response, 201, paymentFixture));
    await client.createPayment(
      { external_id: 'order_123', network: 'TRON', asset: 'USDT', amount: '1' },
      { idempotencyKey: 'order-123-attempt' },
    );
    assert.equal(server.requests[0]!.headers['idempotency-key'], 'order-123-attempt');
  });

  it('createPayment refuses a JavaScript number before any request', async () => {
    const { server, client } = await clientFor((_, response) => sendJson(response, 201, paymentFixture));
    const unsafe = { external_id: 'o', network: 'TRON', asset: 'USDT', amount: 100.5 } as unknown as Parameters<typeof client.createPayment>[0];
    await assert.rejects(client.createPayment(unsafe), InvalidAmountError);
    await assert.rejects(
      client.createPayment({ external_id: 'o', network: 'TRON', asset: 'USDT', amount: '1e3' }),
      InvalidAmountError,
    );
    assert.equal(server.requests.length, 0);
  });

  it('getPayment fetches by id', async () => {
    const { server, client } = await clientFor((_, response) => sendJson(response, 200, paymentFixture));
    const payment = await client.getPayment(paymentFixture.id);
    assert.equal(payment.status, 'waiting');
    assert.equal(server.requests[0]!.url, `/v1/payments/${paymentFixture.id}`);
  });

  it('getPaymentByExternalId uses the external_id query', async () => {
    const { server, client } = await clientFor((_, response) => sendJson(response, 200, paymentFixture));
    await client.getPaymentByExternalId('order 123&x');
    assert.equal(server.requests[0]!.url, '/v1/payments?external_id=order+123%26x');
  });

  it('listPayments passes limit, cursor and status', async () => {
    const page = { data: [paymentFixture], has_more: false, next_cursor: null };
    const { server, client } = await clientFor((_, response) => sendJson(response, 200, page));
    const result = await client.listPayments({ limit: 5, cursor: 'abc', status: 'settled' });
    assert.equal(result.data.length, 1);
    assert.equal(server.requests[0]!.url, '/v1/payments?limit=5&cursor=abc&status=settled');
  });

  it('iteratePayments follows next_cursor until the last page', async () => {
    const { server, client } = await clientFor((request, response) => {
      if (request.url.includes('cursor=c2')) {
        sendJson(response, 200, { data: [{ ...paymentFixture, id: 'pay_b' }], has_more: false, next_cursor: null });
      } else {
        sendJson(response, 200, { data: [{ ...paymentFixture, id: 'pay_a' }], has_more: true, next_cursor: 'c2' });
      }
    });
    const ids: string[] = [];
    for await (const payment of client.iteratePayments({ limit: 1 })) {
      ids.push(payment.id);
    }
    assert.deepEqual(ids, ['pay_a', 'pay_b']);
    assert.equal(server.requests.length, 2);
  });

  it('listNetworks and listFees return typed lists', async () => {
    const networks = { data: [{ network: 'TRON', display_name: 'Tron', paused: false, assets: [{ asset: 'USDT', contract_address: 'T1', decimals: 6, min_amount: '10.000000', max_amount: '100000.000000' }] }] };
    const fees = { data: [{ network: 'TRON', asset: 'USDT', decimals: 6, profile_id: 'p', scope: 'default', service_fee_bps: 50, minimum_service_fee: '0.500000', network_fee_mode: 'fixed', network_fee: '1.000000', effective_from: '2026-10-01T00:00:00Z' }] };
    const { server, client } = await clientFor((request, response) => sendJson(response, 200, request.url === '/v1/networks' ? networks : fees));
    const networkList = await client.listNetworks();
    const feeList = await client.listFees();
    assert.equal(networkList.data[0]!.assets[0]!.min_amount, '10.000000');
    assert.equal(feeList.data[0]!.network_fee, '1.000000');
    assert.deepEqual(server.requests.map((request) => request.url), ['/v1/networks', '/v1/fees']);
  });

  it('refuses merchant operations without an api key', async () => {
    const { server, client } = await clientFor((_, response) => sendJson(response, 200, {}), null);
    await assert.rejects(client.listFees(), GatewayError);
    assert.equal(server.requests.length, 0);
  });
});

describe('public operations', () => {
  const publicOk = (body: object): Responder => (_, response) => sendJson(response, 200, { status: 'success', ...body });

  it('publicCreate keeps the slash in the ticker and sends flags without auth', async () => {
    const { server, client } = await clientFor(
      publicOk({ address_in: 'TIn', address_out: 'TOut', callback_url: 'https://shop.example/hook?order=1', minimum_transaction_coin: '10.000000' }),
      null,
    );
    const result = await client.publicCreate({ ticker: 'trc20/usdt', callback: 'https://shop.example/hook?order=1', address: 'TOut', pending: true, json: false });
    assert.equal(result.minimum_transaction_coin, '10.000000');
    const request = server.requests[0]!;
    assert.equal(request.url, '/trc20/usdt/create/?callback=https%3A%2F%2Fshop.example%2Fhook%3Forder%3D1&address=TOut&pending=1&json=0');
    assert.equal(request.headers['authorization'], undefined);
  });

  it('publicInfo, publicLogs and publicEstimate', async () => {
    const { server, client } = await clientFor((request, response) => {
      if (request.url.includes('/logs/')) {
        sendJson(response, 200, { status: 'success', address_in: 'a', address_out: 'b', callback_url: 'c', notify_pending: true, notify_confirmations: 19, priority: 'default', callbacks: [] });
      } else if (request.url.includes('/estimate/')) {
        sendJson(response, 200, { status: 'success', estimated_cost: '15.000000' });
      } else {
        sendJson(response, 200, { status: 'success', coin: 'USDT', ticker: 'trc20/usdt', minimum_transaction_coin: '10.000000' });
      }
    }, null);
    assert.equal((await client.publicInfo('trc20/usdt')).coin, 'USDT');
    assert.deepEqual((await client.publicLogs('trc20/usdt', 'https://shop.example/hook')).callbacks, []);
    assert.equal((await client.publicEstimate('bep20/usdt')).estimated_cost, '15.000000');
    assert.deepEqual(server.requests.map((request) => request.url), [
      '/trc20/usdt/info/',
      '/trc20/usdt/logs/?callback=https%3A%2F%2Fshop.example%2Fhook',
      '/bep20/usdt/estimate/?addresses=1',
    ]);
  });

  it('publicQrCode, publicConvert, publicTickerConvert and publicCallbackKey', async () => {
    const { server, client } = await clientFor((request, response) => {
      if (request.url.includes('/qrcode/')) {
        sendJson(response, 200, { status: 'success', qr_code: 'data:image/png;base64,AAAA', payment_uri: 'TIn' });
      } else if (request.url.startsWith('/pubkey/')) {
        sendJson(response, 200, { status: 'success', pubkey: '-----BEGIN PUBLIC KEY-----\n-----END PUBLIC KEY-----\n' });
      } else {
        sendJson(response, 200, { status: 'success', value_coin: '108.695652', exchange_rate: '1.086956521739' });
      }
    }, null);
    assert.equal((await client.publicQrCode({ ticker: 'trc20/usdt', address: 'TIn', value: '25.5', size: 256 })).payment_uri, 'TIn');
    assert.equal((await client.publicConvert({ value: '100', from: 'eur', to: 'usd' })).value_coin, '108.695652');
    assert.equal((await client.publicTickerConvert({ ticker: 'trc20/usdt', value: '100', from: 'usd' })).exchange_rate, '1.086956521739');
    assert.match((await client.publicCallbackKey()).pubkey, /BEGIN PUBLIC KEY/);
    assert.deepEqual(server.requests.map((request) => request.url), [
      '/trc20/usdt/qrcode/?address=TIn&value=25.5&size=256',
      '/convert/?value=100&from=eur&to=usd',
      '/trc20/usdt/convert/?value=100&from=usd',
      '/pubkey/',
    ]);
  });

  it('public methods refuse numeric money values', async () => {
    const { client } = await clientFor(publicOk({}), null);
    const numeric = 100 as unknown as string;
    await assert.rejects(client.publicConvert({ value: numeric, from: 'eur', to: 'usd' }), InvalidAmountError);
    await assert.rejects(client.publicQrCode({ ticker: 'trc20/usdt', address: 'a', value: numeric }), InvalidAmountError);
  });

  it('rejects a ticker without a slash segment', async () => {
    const { client } = await clientFor(publicOk({}), null);
    await assert.rejects(client.publicInfo('usdt/'), GatewayError);
    await assert.rejects(client.publicInfo(''), GatewayError);
  });
});

describe('errors', () => {
  it('maps a 4xx envelope to ApiError with code and request id', async () => {
    const { client } = await clientFor((_, response) =>
      sendJson(response, 409, { error: { code: 'DUPLICATE_EXTERNAL_ID', message: 'external_id already used', request_id: 'req_1' } }),
    );
    await assert.rejects(
      client.createPayment({ external_id: 'order_123', network: 'TRON', asset: 'USDT', amount: '5' }),
      (error: unknown) => {
        assert.ok(error instanceof ApiError);
        assert.ok(!(error instanceof RateLimitError));
        assert.equal(error.status, 409);
        assert.equal(error.code, 'DUPLICATE_EXTERNAL_ID');
        assert.equal(error.requestId, 'req_1');
        assert.equal(error.message, 'external_id already used');
        return true;
      },
    );
  });

  it('maps 404 PAYMENT_NOT_FOUND', async () => {
    const { client } = await clientFor((_, response) =>
      sendJson(response, 404, { error: { code: 'PAYMENT_NOT_FOUND', message: 'payment not found', request_id: 'req_2' } }),
    );
    await assert.rejects(client.getPayment('pay_missing'), (error: unknown) => error instanceof ApiError && error.code === 'PAYMENT_NOT_FOUND');
  });

  it('maps the public error envelope', async () => {
    const { client } = await clientFor((_, response) => sendJson(response, 400, { status: 'error', error: 'Invalid ticker' }), null);
    await assert.rejects(client.publicInfo('trc20/usdt'), (error: unknown) => {
      assert.ok(error instanceof ApiError);
      assert.equal(error.message, 'Invalid ticker');
      assert.equal(error.code, undefined);
      return true;
    });
  });

  it('maps a plain text 503 from pubkey', async () => {
    const { client } = await clientFor((_, response) => {
      response.writeHead(503, { 'Content-Type': 'text/plain' });
      response.end('public key unavailable');
    }, null);
    await assert.rejects(client.publicCallbackKey(), (error: unknown) => {
      assert.ok(error instanceof ApiError);
      assert.equal(error.status, 503);
      assert.equal(error.message, 'public key unavailable');
      return true;
    });
  });

  it('maps 429 to RateLimitError with the Retry-After seconds', async () => {
    const { client } = await clientFor((_, response) =>
      sendJson(response, 429, { error: { code: 'RATE_LIMITED', message: 'rate limit exceeded', request_id: 'req_3' } }, { 'Retry-After': '7' }),
    );
    await assert.rejects(client.listFees(), (error: unknown) => {
      assert.ok(error instanceof RateLimitError);
      assert.ok(error instanceof ApiError);
      assert.equal(error.status, 429);
      assert.equal(error.code, 'RATE_LIMITED');
      assert.equal(error.retryAfterSeconds, 7);
      return true;
    });
  });

  it('understands an HTTP date Retry-After and a missing one', async () => {
    const future = new Date(Date.now() + 30_000).toUTCString();
    let withHeader = true;
    const { client } = await clientFor((_, response) =>
      sendJson(response, 429, { error: { code: 'RATE_LIMITED', message: 'slow down', request_id: 'r' } }, withHeader ? { 'Retry-After': future } : {}),
    );
    await assert.rejects(client.listFees(), (error: unknown) => {
      assert.ok(error instanceof RateLimitError);
      assert.ok(error.retryAfterSeconds !== undefined && error.retryAfterSeconds >= 28 && error.retryAfterSeconds <= 31);
      return true;
    });
    withHeader = false;
    await assert.rejects(client.listFees(), (error: unknown) => error instanceof RateLimitError && error.retryAfterSeconds === undefined);
  });

  it('maps a public 429 to RateLimitError', async () => {
    const { client } = await clientFor((_, response) => sendJson(response, 429, { status: 'error', error: 'Too many requests' }, { 'Retry-After': '2' }), null);
    await assert.rejects(client.publicInfo('trc20/usdt'), (error: unknown) => error instanceof RateLimitError && error.retryAfterSeconds === 2);
  });

  it('reports a malformed success body', async () => {
    const { client } = await clientFor((_, response) => {
      response.writeHead(200, { 'Content-Type': 'application/json' });
      response.end('<html>proxy error');
    });
    await assert.rejects(client.getPayment('pay_x'), (error: unknown) => {
      assert.ok(error instanceof MalformedResponseError);
      assert.equal(error.bodyText, '<html>proxy error');
      return true;
    });
  });

  it('reports a JSON body that is not an object, and a public body without success status', async () => {
    const { client } = await clientFor((request, response) => sendJson(response, 200, request.url === '/v1/fees' ? [] : { status: 'weird' }), 'k');
    await assert.rejects(client.listFees(), MalformedResponseError);
    await assert.rejects(client.publicCallbackKey(), MalformedResponseError);
  });

  it('keeps the raw text when an error body is not JSON', async () => {
    const { client } = await clientFor((_, response) => {
      response.writeHead(502);
      response.end('bad gateway');
    });
    await assert.rejects(client.listNetworks(), (error: unknown) => error instanceof ApiError && error.status === 502 && error.message === 'bad gateway');
  });

  it('reports an aborted request', async () => {
    const { client } = await clientFor(() => undefined);
    const controller = new AbortController();
    const pending = client.getPayment('pay_slow', { signal: controller.signal });
    setTimeout(() => controller.abort(), 30);
    await assert.rejects(pending, (error: unknown) => {
      assert.ok(error instanceof NetworkError);
      assert.equal(error.aborted, true);
      return true;
    });
  });

  it('reports an already aborted signal without sending', async () => {
    const { server, client } = await clientFor((_, response) => sendJson(response, 200, paymentFixture));
    const controller = new AbortController();
    controller.abort();
    await assert.rejects(client.getPayment('pay_x', { signal: controller.signal }), (error: unknown) => error instanceof NetworkError && error.aborted);
    assert.equal(server.requests.length, 0);
  });

  it('times out a request that never answers', async () => {
    const { client } = await clientFor(() => undefined, 'k', 50);
    await assert.rejects(client.listFees(), (error: unknown) => error instanceof NetworkError && /timed out/.test(error.message));
  });

  it('reports a dropped connection', async () => {
    const { client } = await clientFor((_, response) => response.destroy());
    await assert.rejects(client.listFees(), (error: unknown) => error instanceof NetworkError && error.aborted === false);
  });
});

it('uses the Suawave API by default', async () => {
  const client = new GatewayClient({ fetch: async (url) => {
    assert.equal(url, 'https://api.suawave.com/v1/payments/payment-1');
    return new Response(JSON.stringify(paymentFixture));
  }, apiKey: 'key_test' });
  assert.deepEqual(await client.getPayment('payment-1'), paymentFixture);
});
