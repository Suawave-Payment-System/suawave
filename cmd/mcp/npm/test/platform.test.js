'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { target, releaseBase } = require('../lib/platform');

test('maps node platforms to release file names', () => {
  assert.equal(target('darwin', 'arm64').file, 'suawave-mcp-darwin-arm64');
  assert.equal(target('linux', 'x64').file, 'suawave-mcp-linux-amd64');
  assert.equal(target('win32', 'x64').file, 'suawave-mcp-windows-amd64.exe');
});

test('rejects unsupported platforms', () => {
  assert.throws(() => target('freebsd', 'x64'));
  assert.throws(() => target('linux', 'ia32'));
  assert.throws(() => target('win32', 'arm64'));
});

test('release base carries org and version', () => {
  assert.equal(releaseBase('ORG', '1.2.3'), 'https://github.com/ORG/suawave/releases/download/mcp-v1.2.3');
});
