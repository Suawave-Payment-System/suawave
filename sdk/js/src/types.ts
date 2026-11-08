export type DecimalString = string;

export type ErrorCode =
  | 'UNAUTHORIZED'
  | 'RATE_LIMITED'
  | 'INVALID_REQUEST'
  | 'INVALID_AMOUNT'
  | 'AMOUNT_BELOW_MINIMUM'
  | 'UNSUPPORTED_NETWORK'
  | 'UNSUPPORTED_ASSET'
  | 'INVALID_SETTLEMENT_ADDRESS'
  | 'IDEMPOTENCY_CONFLICT'
  | 'DUPLICATE_EXTERNAL_ID'
  | 'PAYMENT_NOT_FOUND'
  | 'NOT_FOUND'
  | 'METHOD_NOT_ALLOWED'
  | 'SERVICE_TEMPORARILY_UNAVAILABLE';

export type PaymentStatus =
  | 'waiting'
  | 'detected'
  | 'confirming'
  | 'confirmed'
  | 'settlement_pending'
  | 'settling'
  | 'settled'
  | 'expired'
  | 'late_payment'
  | 'underpaid'
  | 'overpaid'
  | 'rejected'
  | 'manual_review';

export type PaymentOutcome = 'exact' | 'underpaid' | 'overpaid' | 'late';

export type SettlementStatus =
  | 'pending'
  | 'processing'
  | 'broadcast'
  | 'confirmed'
  | 'manual_review'
  | 'failed_terminal'
  | 'cancelled_before_broadcast';

export interface CreatePaymentRequest {
  external_id: string;
  network: string;
  asset: string;
  amount: DecimalString;
  settlement_address?: string;
}

export interface Transfer {
  tx_hash: string;
  log_index: number;
  amount: DecimalString;
  status: 'credited' | 'held';
  observed_at: string;
}

export interface Settlement {
  status: SettlementStatus;
  tx_hash: string | null;
  net_amount: DecimalString;
  service_fee: DecimalString;
  network_fee: DecimalString;
  confirmed_at: string | null;
}

export interface Payment {
  id: string;
  external_id: string;
  network: string;
  asset: string;
  amount: DecimalString;
  received_amount: DecimalString;
  deposit_address: string;
  settlement_address: string;
  status: PaymentStatus;
  outcome: PaymentOutcome | null;
  expires_at: string;
  confirmed_at: string | null;
  created_at: string;
  transfers: Transfer[];
  settlement?: Settlement;
}

export interface PaymentPage {
  data: Payment[];
  has_more: boolean;
  next_cursor: string | null;
}

export interface ListPaymentsParams {
  limit?: number;
  cursor?: string;
  status?: PaymentStatus;
}

export interface NetworkAsset {
  asset: string;
  contract_address: string;
  decimals: number;
  min_amount: DecimalString;
  max_amount: DecimalString;
}

export interface Network {
  network: string;
  display_name: string;
  paused: boolean;
  assets: NetworkAsset[];
}

export interface NetworkList {
  data: Network[];
}

export interface Fee {
  network: string;
  asset: string;
  decimals: number;
  profile_id: string;
  scope: 'merchant' | 'default';
  service_fee_bps: number;
  minimum_service_fee: DecimalString;
  network_fee_mode: 'fixed';
  network_fee: DecimalString;
  effective_from: string;
}

export interface FeeList {
  data: Fee[];
}

export interface RequestOptions {
  signal?: AbortSignal;
}

export interface CreatePaymentOptions extends RequestOptions {
  idempotencyKey?: string;
}

export interface PublicCreateParams {
  ticker: string;
  callback: string;
  address: string;
  pending?: boolean;
  post?: boolean;
  json?: boolean;
  convert?: boolean;
}

export interface PublicCreateResponse {
  status: 'success';
  address_in: string;
  address_out: string;
  callback_url: string;
  minimum_transaction_coin: DecimalString;
}

export interface PublicInfoResponse {
  status: 'success';
  coin: string;
  ticker: string;
  minimum_transaction_coin: DecimalString;
}

export interface PublicLogEntry {
  request_url: string;
  response_status: number;
  responses: string;
  next_try: string | null;
  pending: 0 | 1;
  confirmed: 0 | 1;
}

export interface PublicLogCallback {
  txid_in: string;
  txid_out: string;
  value_coin: DecimalString;
  value_forwarded_coin: DecimalString;
  confirmations: number;
  last_update: string;
  result: 'pending' | 'sent' | 'done';
  fee_percent: DecimalString;
  fee_coin: DecimalString;
  logs: PublicLogEntry[];
}

export interface PublicLogsResponse {
  status: 'success';
  address_in: string;
  address_out: string;
  callback_url: string;
  notify_pending: boolean;
  notify_confirmations: number;
  priority: 'default';
  callbacks: PublicLogCallback[];
}

export interface PublicEstimateResponse {
  status: 'success';
  estimated_cost: DecimalString;
}

export interface PublicQrCodeParams {
  ticker: string;
  address: string;
  value?: DecimalString;
  size?: number;
}

export interface PublicQrCodeResponse {
  status: 'success';
  qr_code: string;
  payment_uri: string;
}

export interface PublicConvertParams {
  value: DecimalString;
  from: string;
  to: string;
}

export interface PublicTickerConvertParams {
  ticker: string;
  value: DecimalString;
  from: string;
}

export interface PublicConvertResponse {
  status: 'success';
  value_coin: DecimalString;
  exchange_rate: DecimalString;
}

export interface PublicKeyResponse {
  status: 'success';
  pubkey: string;
}

export type WebhookEventType =
  | 'payment.detected'
  | 'payment.confirmed'
  | 'payment.underpaid'
  | 'payment.overpaid'
  | 'payment.expired'
  | 'payment.late'
  | 'settlement.broadcast'
  | 'settlement.confirmed'
  | 'settlement.failed';

export interface WebhookEventData {
  payment_id: string;
  external_id: string;
  network: string;
  asset: string;
  amount: DecimalString;
  tx_hash: string | null;
  status: string;
  received_amount: DecimalString;
  outcome: PaymentOutcome | null;
  settlement_amount?: DecimalString;
}

export interface WebhookEvent {
  id: string;
  type: WebhookEventType;
  created_at: string;
  data: WebhookEventData;
}

export interface PublicCallbackBase {
  uuid: string;
  address_in: string;
  address_out: string;
  txid_in: string;
  coin: string;
  price?: DecimalString;
}

export interface PublicPendingCallback extends PublicCallbackBase {
  pending: '1';
}

export interface PublicConfirmedCallback extends PublicCallbackBase {
  pending: '0';
  txid_out: string;
  confirmations: string;
  value_coin: DecimalString;
  value_forwarded_coin: DecimalString;
  fee_coin: DecimalString;
  value_coin_convert?: string;
  value_forwarded_coin_convert?: string;
}
