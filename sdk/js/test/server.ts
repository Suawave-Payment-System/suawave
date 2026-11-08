import { createServer } from 'node:http';
import type { IncomingMessage, Server, ServerResponse } from 'node:http';
import type { AddressInfo } from 'node:net';

export interface RecordedRequest {
  method: string;
  url: string;
  headers: IncomingMessage['headers'];
  body: string;
}

export type Responder = (request: RecordedRequest, response: ServerResponse) => void;

export interface TestServer {
  baseUrl: string;
  requests: RecordedRequest[];
  close(): Promise<void>;
}

export async function startServer(responder: Responder): Promise<TestServer> {
  const requests: RecordedRequest[] = [];
  const server: Server = createServer((request, response) => {
    const chunks: Buffer[] = [];
    request.on('data', (chunk: Buffer) => chunks.push(chunk));
    request.on('end', () => {
      const recorded: RecordedRequest = {
        method: request.method ?? '',
        url: request.url ?? '',
        headers: request.headers,
        body: Buffer.concat(chunks).toString('utf8'),
      };
      requests.push(recorded);
      responder(recorded, response);
    });
  });
  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
  const { port } = server.address() as AddressInfo;
  return {
    baseUrl: `http://127.0.0.1:${port}`,
    requests,
    close: () =>
      new Promise<void>((resolve) => {
        server.closeAllConnections();
        server.close(() => resolve());
      }),
  };
}

export function sendJson(response: ServerResponse, status: number, body: unknown, headers: Record<string, string> = {}): void {
  response.writeHead(status, { 'Content-Type': 'application/json', ...headers });
  response.end(JSON.stringify(body));
}

export const paymentFixture = {
  id: 'pay_0192f5d6-0000-7000-8000-000000000001',
  external_id: 'order_123',
  network: 'TRON',
  asset: 'USDT',
  amount: '100.500000',
  received_amount: '0',
  deposit_address: 'TDepositAddressExample0000000000',
  settlement_address: 'TXyzPayoutAddressExample0000000000000',
  status: 'waiting',
  outcome: null,
  expires_at: '2026-10-22T13:00:00Z',
  confirmed_at: null,
  created_at: '2026-10-22T12:00:00Z',
  transfers: [],
};
