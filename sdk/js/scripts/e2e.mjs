import { randomUUID } from 'node:crypto';
import { ApiError, GatewayClient } from '../dist/esm/index.js';
import { SignatureVerificationError, verifyWebhookSignature } from '../dist/esm/verify.js';

const usage = 'usage: node scripts/e2e.mjs <base-url> <api-key> [--wait-for-status <status>] [--timeout-seconds <n>]';

function readOption(name, fallback) {
  const index = process.argv.indexOf(name);
  return index === -1 ? fallback : process.argv[index + 1];
}

const [baseUrl, apiKey] = process.argv.slice(2);
if (!baseUrl || !apiKey || baseUrl.startsWith('--') || apiKey.startsWith('--')) {
  console.error(usage);
  process.exit(2);
}
const waitForStatus = readOption('--wait-for-status', undefined);
const timeoutSeconds = Number(readOption('--timeout-seconds', '120'));

let failures = 0;

function check(name, condition, detail = '') {
  if (condition) {
    console.log(`ok   ${name}`);
  } else {
    failures += 1;
    console.log(`FAIL ${name} ${detail}`);
  }
}

async function expectApiError(name, code, action) {
  try {
    await action();
    check(name, false, 'no error was thrown');
  } catch (error) {
    check(name, error instanceof ApiError && error.code === code, `got ${error?.name}: ${error?.message}`);
  }
}

const client = new GatewayClient({ baseUrl, apiKey });

const networks = await client.listNetworks();
const network = networks.data.find((candidate) => !candidate.paused && candidate.assets.length > 0);
check('listNetworks returns an unpaused network with an asset', network !== undefined);
if (network === undefined) {
  process.exit(1);
}
const asset = network.assets[0];
check('network amounts are decimal strings', typeof asset.min_amount === 'string' && typeof asset.max_amount === 'string');

const fees = await client.listFees();
check('listFees returns fee profiles with string amounts', fees.data.every((fee) => typeof fee.network_fee === 'string'));

const externalId = `sdk-js-e2e-${randomUUID()}`;
const idempotencyKey = randomUUID();
const request = { external_id: externalId, network: network.network, asset: asset.asset, amount: asset.min_amount };

const created = await client.createPayment(request, { idempotencyKey });
check('createPayment returns a waiting payment', created.status === 'waiting' && created.id.startsWith('pay_'));
check('created amount equals the requested decimal string', created.amount === asset.min_amount, `${created.amount} vs ${asset.min_amount}`);

const replayed = await client.createPayment(request, { idempotencyKey });
check('same idempotency key returns the same payment', replayed.id === created.id);

await expectApiError('same external_id with a new key is DUPLICATE_EXTERNAL_ID', 'DUPLICATE_EXTERNAL_ID', () =>
  client.createPayment(request),
);

const fetched = await client.getPayment(created.id);
check('getPayment returns the created payment', fetched.id === created.id);

const byExternalId = await client.getPaymentByExternalId(externalId);
check('getPaymentByExternalId returns the created payment', byExternalId.id === created.id);

const listed = [];
for await (const payment of client.iteratePayments({ limit: 5 })) {
  listed.push(payment.id);
  if (listed.includes(created.id) || listed.length >= 200) {
    break;
  }
}
check('iteratePayments reaches the created payment', listed.includes(created.id));

await expectApiError('unknown payment is PAYMENT_NOT_FOUND', 'PAYMENT_NOT_FOUND', () =>
  client.getPayment('pay_00000000-0000-7000-8000-000000000000'),
);

const wrongKeyClient = new GatewayClient({ baseUrl, apiKey: `${apiKey}-wrong` });
await expectApiError('wrong api key is UNAUTHORIZED', 'UNAUTHORIZED', () => wrongKeyClient.listFees());

const body = JSON.stringify({ id: 'evt_x', type: 'payment.confirmed', created_at: '2026-01-01T00:00:00Z', data: {} });
try {
  verifyWebhookSignature({
    secret: 'a'.repeat(64),
    headers: {
      'X-Gateway-Timestamp': String(Math.floor(Date.now() / 1000)),
      'X-Gateway-Signature': `v1=${'0'.repeat(64)}`,
    },
    rawBody: body,
  });
  check('a webhook with a bad signature is rejected', false, 'it was accepted');
} catch (error) {
  check('a webhook with a bad signature is rejected', error instanceof SignatureVerificationError && error.reason === 'invalid_signature');
}

console.log(`deposit ${created.amount} ${created.asset} on ${created.network} to ${created.deposit_address}`);

if (waitForStatus !== undefined) {
  console.log(`waiting up to ${timeoutSeconds}s for status "${waitForStatus}" (deposit on the mock chain now)`);
  const deadline = Date.now() + timeoutSeconds * 1000;
  let current = fetched;
  while (current.status !== waitForStatus && Date.now() < deadline) {
    await new Promise((resolve) => setTimeout(resolve, 2000));
    current = await client.getPayment(created.id);
  }
  check(`payment reached status "${waitForStatus}"`, current.status === waitForStatus, `last status "${current.status}"`);
  if (current.status === waitForStatus) {
    check('received_amount is a decimal string', typeof current.received_amount === 'string');
    console.log(`final: status=${current.status} outcome=${current.outcome} received=${current.received_amount}`);
  }
}

if (failures > 0) {
  console.log(`${failures} check(s) failed`);
  process.exit(1);
}
console.log('all checks passed');
