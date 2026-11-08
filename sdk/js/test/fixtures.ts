export const webhookFixture = {
  secret: '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
  timestamp: '1761134400',
  nowMs: 1761134400000,
  rawBody: '{"id":"evt_0192f5d6-8a2b-7c3d-9e4f-a1b2c3d4e5f6","type":"payment.confirmed","created_at":"2026-10-22T12:00:00.123456Z","data":{"payment_id":"pay_0192f5d6-0000-7000-8000-000000000001","external_id":"order_123","network":"TRON","asset":"USDT","amount":"100.000000","tx_hash":"4b1c","status":"confirmed","received_amount":"100.000000","outcome":null}}',
  signature: 'e337eadd24f065419c859b15f7945de8d87f61359bd15f4300fb72b4c562d33e',
};

export const callbackFixture = {
  publicKeyPem: `-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAvGdbkr79jvjx+ivgRqOP
zfjXTIepoy+wkq4asvlBGGaeuCNCI0yJgrth2M/K73kKAuOyks4maAs84G1tz/5M
CKB8S+hEKTWAWMWXevCmmgzjIEowfgOn/G+FEI5YZSNZtREPNUu+nmxhrrL2GDJW
s3MlZMVmHUIHNbEsaTqcD6bdCcRN/jWZdEGZXxaXOgirN/QYSvUb23Lc3S1E1pOq
EtbyoAfC5CPsI/brtaD5rKH3m98R8KA+Q1bemPwNqeuv9mI88wufa4kutjjMTw30
ch5HUOrzLh0NRvNwHWCabmNsPoK3IsfvpiKNk9hFZZ4YEIdP8kKoyGI97Oo7TLi1
9wIDAQAB
-----END PUBLIC KEY-----
`,
  signedUrl: 'https://shop.example/hook?order=1001&nonce=7f3a9c&address_in=TDepositAddressExample0000000000&coin=trc20_usdt&pending=0',
  urlSignature: 'EQNZdMJfBy6NH6lMHoBvPGqbeOP0HxahCKaRMWco3fwemdikm3bMNYDJOYsuDU6xnkPW58NFQp9/9ahGnJpvG4aeWIVCnjyH9DSLuhFwisAgr7wqXp8C8MjjWHLZ6bNE5nJZqVI2mXSuFzsigk0YfASmQ15kJh/rPwtROS6iASM7rTd450KrTvQNjeA4memV/nU1VAcpJSOw6VYYdZSXHKM6xIEsgT0Sf6QU2dLvo1UmXt/FGrsWIZSwD3l2On7nQG29mas2ACjElMJl1EQ0eTU+BDMqaf7uOKpEoZV1VniOfWYU65Pd6ufo/w76PcHyO/tUw0U/cRX3JurwuFercg==',
  rawBody: '{"uuid":"8c4e2a61-5b3d-47f9-a0e2-1d6c9b7f3e45","address_in":"TDepositAddressExample0000000000","address_out":"TXyzPayoutAddressExample0000000000000","txid_in":"9f2c","coin":"trc20_usdt","pending":"0","txid_out":"1a2b","confirmations":"21","value_coin":"100.000000","value_forwarded_coin":"98.200000","fee_coin":"1.800000"}',
  bodySignature: 'sSJATHFzG0HgRSZRdwD5CMGv4C+jjAaRsz70WmGdiwMrp6VieMbZrpPKBJY/jMAghGAHNnTlvv9S6OM13/WAyFMOxsKJp5Y1rybT5MBKbQlqAes9lKp7xoX/bu3K5BanWHLxX2BTXrbtHLrB3aQGNZ9QY1SEqqF34TStE5GnFpYHwFWdr6hlkEjARqtVpBVSNq4AmVtiBzzHXIK+U8t9WudqHRinV2T8zYbTbm9D7qDDBtLTeby1FSrb31wVioC0qN2v2pgbboZWr6+fiwcoonBLH4Emj5ldoZrwYDEsN+OZGSoIlDo6wDhZsNn0fo0JdDGpGaMYbGYdcBuFPDR7qA==',
};

export const weakKeyFixture = {
  publicKeyPem: `-----BEGIN PUBLIC KEY-----
MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC3/GbbBF4JAL18L2J/rgu/yD6e
h29twUKcig/DRwF4eeKGjkDFaSmtrZkEUpZxcmyFlx3EtlWoXUmLPMdLsmBr/MT3
YXuv0b/U+GgDhYo0CEX9lcJ+s6MAXR1KtcYaoualqTcO+NoP5RAwMrxHVjdXRe2U
i9QOAB85MykDRovFcQIDAQAB
-----END PUBLIC KEY-----
`,
  urlSignature: 'ZCczPefoIg5ygfPU+tM7G31RhrRlqeSsFlQpynaNpVHV2XCblv8knRiPWyJyt1PLWNHFGPYxYSP+J6TaRjqm3v5qo/23e5Q3XspB8Fp7LSCXs/NzLfXvpXIOxseKnbTqY9J1Fb/7IBVWZMV/ok8xH08exxssp3QGcOPqiPlS1OU=',
};
