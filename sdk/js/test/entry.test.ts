import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { describe, it } from 'node:test';

const sourceDirectory = new URL('../../src/', import.meta.url);

function importsOf(file: string): string[] {
  const source = readFileSync(new URL(file, sourceDirectory), 'utf8');
  return [...source.matchAll(/from\s+'([^']+)'/g)].map((match) => match[1]!);
}

function reachableSpecifiers(entry: string, seen = new Set<string>()): Set<string> {
  if (seen.has(entry)) {
    return seen;
  }
  seen.add(entry);
  for (const specifier of importsOf(entry.replace(/\.js$/, '.ts'))) {
    if (specifier.startsWith('.')) {
      reachableSpecifiers(specifier.replace('./', ''), seen);
    } else {
      seen.add(specifier);
    }
  }
  return seen;
}

describe('entry points', () => {
  it('the main entry reaches no node built-in', () => {
    const reachable = [...reachableSpecifiers('index.js')];
    assert.deepEqual(reachable.filter((specifier) => !specifier.endsWith('.js')), []);
    assert.ok(!reachable.includes('verify.js'));
  });

  it('only the verify entry reaches node:crypto', () => {
    const reachable = [...reachableSpecifiers('verify.js')];
    assert.ok(reachable.includes('node:crypto'));
  });
});
