import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

// Every example ends its output lines with "// -> expected". The test runs the example and
// compares stdout line by line, so the README (which embeds these files) cannot drift.
const dir = fileURLToPath(new URL('../examples/', import.meta.url));

function expected(source) {
  return [...source.matchAll(/\/\/ -> (.*)$/gm)].map((m) => m[1]);
}

let hasSqlite = true;
try {
  await import('node:sqlite');
} catch {
  hasSqlite = false;
}

let php = null;
for (const bin of [process.env.PHP_BINARY, 'php'].filter(Boolean)) {
  try {
    execFileSync(bin, ['-v'], { stdio: 'ignore' });
    php = bin;
    break;
  } catch {
    // try the next candidate
  }
}

let phpSqlite = false;
if (php) {
  try {
    execFileSync(php, ['-r', "exit(in_array('sqlite', PDO::getAvailableDrivers(), true) ? 0 : 1);"], { stdio: 'ignore' });
    phpSqlite = true;
  } catch {
    phpSqlite = false;
  }
}

// demo.mjs prints a walkthrough rather than "// ->" lines; test/demo.test.js covers it.
for (const file of readdirSync(dir).sort().filter((f) => f !== 'demo.mjs')) {
  const isPhp = file.endsWith('.php');
  const skip =
    (isPhp && !php && 'php not installed') ||
    (file === 'pdo-sqlite.php' && !phpSqlite && 'pdo_sqlite not available') ||
    (file === 'sqlite.mjs' && !hasSqlite && 'node:sqlite not available');
  test(`example ${file}`, { skip }, () => {
    const source = readFileSync(dir + file, 'utf8');
    const out = isPhp
      ? execFileSync(php, [dir + file], { encoding: 'utf8' })
      : execFileSync(process.execPath, ['--no-warnings', dir + file], { encoding: 'utf8' });
    assert.deepEqual(out.trimEnd().split('\n'), expected(source));
  });
}
