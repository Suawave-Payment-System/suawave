'use strict';

const OS = { darwin: 'darwin', linux: 'linux', win32: 'windows' };
const CPU = { x64: 'amd64', arm64: 'arm64' };

function target(platform, arch) {
  const os = OS[platform];
  const cpu = CPU[arch];
  if (!os || !cpu) {
    throw new Error('unsupported platform ' + platform + '/' + arch);
  }
  if (os === 'windows' && cpu !== 'amd64') {
    throw new Error('unsupported platform ' + platform + '/' + arch);
  }
  const ext = os === 'windows' ? '.exe' : '';
  return { os, cpu, file: 'suawave-mcp-' + os + '-' + cpu + ext };
}

function releaseBase(org, version) {
  return 'https://github.com/' + org + '/suawave/releases/download/mcp-v' + version;
}

module.exports = { target, releaseBase };
