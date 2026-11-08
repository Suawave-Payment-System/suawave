import { InvalidAmountError } from './errors.js';
import type { DecimalString } from './types.js';

const decimalPattern = /^[0-9]+(\.[0-9]+)?$/;

export function assertDecimalString(value: unknown, field = 'amount'): asserts value is DecimalString {
  if (typeof value !== 'string') {
    throw new InvalidAmountError(`${field} must be a decimal string such as "100.50", not ${typeof value}`);
  }
  if (!decimalPattern.test(value)) {
    throw new InvalidAmountError(`${field} must be a plain decimal string without sign or exponent, got "${value}"`);
  }
}

export function toBaseUnits(amount: DecimalString, decimals: number): bigint {
  assertDecimalString(amount);
  if (!Number.isInteger(decimals) || decimals < 0 || decimals > 36) {
    throw new InvalidAmountError('decimals must be an integer between 0 and 36');
  }
  const [whole = '0', fraction = ''] = amount.split('.');
  if (fraction.length > decimals) {
    throw new InvalidAmountError(`amount "${amount}" has more than ${decimals} decimal places`);
  }
  return BigInt(whole + fraction.padEnd(decimals, '0'));
}

export function fromBaseUnits(units: bigint, decimals: number): DecimalString {
  if (units < 0n) {
    throw new InvalidAmountError('base units must not be negative');
  }
  if (!Number.isInteger(decimals) || decimals < 0 || decimals > 36) {
    throw new InvalidAmountError('decimals must be an integer between 0 and 36');
  }
  if (decimals === 0) {
    return units.toString();
  }
  const digits = units.toString().padStart(decimals + 1, '0');
  return `${digits.slice(0, -decimals)}.${digits.slice(-decimals)}`;
}
