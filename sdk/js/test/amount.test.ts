import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { InvalidAmountError, assertDecimalString, fromBaseUnits, toBaseUnits } from '../src/index.js';

describe('amount helpers', () => {
  it('converts decimal strings to exact base units', () => {
    assert.equal(toBaseUnits('100.5', 6), 100500000n);
    assert.equal(toBaseUnits('0.000001', 6), 1n);
    assert.equal(toBaseUnits('123456789012345678901234567890.123456789012345678', 18), 123456789012345678901234567890123456789012345678n);
    assert.equal(toBaseUnits('7', 0), 7n);
  });

  it('converts base units back without losing digits', () => {
    assert.equal(fromBaseUnits(100500000n, 6), '100.500000');
    assert.equal(fromBaseUnits(1n, 6), '0.000001');
    assert.equal(fromBaseUnits(0n, 6), '0.000000');
    assert.equal(fromBaseUnits(7n, 0), '7');
  });

  it('rejects too many places, signs, exponents and non strings', () => {
    assert.throws(() => toBaseUnits('0.0000001', 6), InvalidAmountError);
    for (const bad of ['-1', '+1', '1e3', '1,5', '', '.5', '5.', ' 5', '0x10']) {
      assert.throws(() => assertDecimalString(bad), InvalidAmountError, bad);
    }
    assert.throws(() => assertDecimalString(5 as unknown), InvalidAmountError);
  });
});
