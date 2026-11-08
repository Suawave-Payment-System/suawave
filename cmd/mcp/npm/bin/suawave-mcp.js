#!/usr/bin/env node
'use strict';

const { spawn } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const name = process.platform === 'win32' ? 'suawave-mcp.exe' : 'suawave-mcp';
const binary = process.env.SUAWAVE_MCP_BIN || path.join(__dirname, '..', 'vendor', name);

if (!fs.existsSync(binary)) {
  process.stderr.write('suawave-mcp: binary not found at ' + binary + '; reinstall @suawave/mcp or set SUAWAVE_MCP_BIN\n');
  process.exit(1);
}

const child = spawn(binary, process.argv.slice(2), { stdio: 'inherit' });
child.on('error', (failure) => {
  process.stderr.write('suawave-mcp: ' + failure.message + '\n');
  process.exit(1);
});
child.on('exit', (code, signal) => {
  if (signal) {
    process.kill(process.pid, signal);
    return;
  }
  process.exit(code === null ? 1 : code);
});
