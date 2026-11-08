'use strict';

const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { target, releaseBase } = require('./platform');

const pkg = require('../package.json');

async function download(url) {
  const response = await fetch(url, { redirect: 'follow' });
  if (!response.ok) {
    throw new Error('download failed: ' + url + ' answered ' + response.status);
  }
  return Buffer.from(await response.arrayBuffer());
}

async function main() {
  if (process.env.SUAWAVE_MCP_BIN || process.env.SUAWAVE_MCP_SKIP_DOWNLOAD) {
    return;
  }
  const { file } = target(process.platform, process.arch);
  const org = pkg.mcpName.split('/')[0].replace('io.github.', '');
  const base = process.env.SUAWAVE_MCP_RELEASE_URL || releaseBase(org, pkg.version);
  const sums = (await download(base + '/SHA256SUMS')).toString('utf8');
  const line = sums.split('\n').find((entry) => entry.trim().endsWith(' ' + file) || entry.trim().endsWith('*' + file));
  if (!line) {
    throw new Error('no checksum for ' + file);
  }
  const expected = line.trim().split(/\s+/)[0].toLowerCase();
  const body = await download(base + '/' + file);
  const actual = crypto.createHash('sha256').update(body).digest('hex');
  if (actual !== expected) {
    throw new Error('checksum mismatch for ' + file);
  }
  const dir = path.join(__dirname, '..', 'vendor');
  fs.mkdirSync(dir, { recursive: true });
  const out = path.join(dir, file.endsWith('.exe') ? 'suawave-mcp.exe' : 'suawave-mcp');
  fs.writeFileSync(out, body, { mode: 0o755 });
}

main().catch((failure) => {
  process.stderr.write('suawave-mcp install: ' + failure.message + '\n');
  process.exit(1);
});
