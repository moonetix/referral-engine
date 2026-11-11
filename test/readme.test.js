import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// README code blocks marked <!-- example: examples/x --> must equal that file. PHP files load the
// library from the repository; the README shows the Composer paths instead.
const root = new URL('../', import.meta.url);
const readme = readFileSync(new URL('README.md', root), 'utf8');
const blocks = [...readme.matchAll(/<!-- example: (\S+) -->\n```\w+\n([\s\S]*?)```/g)];

test('README embeds the examples', () => {
  assert.ok(blocks.length >= 5, `found ${blocks.length} marked blocks`);
});

for (const [, file, code] of blocks) {
  test(`README block matches ${file}`, () => {
    const source = readFileSync(new URL(file, root), 'utf8')
      .replace("require __DIR__ . '/../php/autoload.php';", "require __DIR__ . '/vendor/autoload.php';")
      .replace("__DIR__ . '/../schema/", "__DIR__ . '/vendor/moonetix/referral-engine/schema/");
    assert.equal(code, source);
  });
}
