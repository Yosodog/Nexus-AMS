// node --test scripts/*.test.mjs
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { compareVersions, nextRelease, parseTag } from './release-version.mjs';

const CURRENT = ['v0.1.0-alpha', 'v0.2.0-alpha', 'v0.3.0-alpha', 'v0.4.0-alpha', 'v0.5.0-alpha', 'v0.7.0-alpha', 'v0.8.0-beta'];

function release(tags, bump, beta = false, version = '', reachable = tags) {
  return nextRelease({ tags, reachable, bump, beta, version });
}

test('orders tags by semver precedence', () => {
  const tags = ['v1.0.0', 'v1.0.0-beta.10', 'v1.0.0-beta.2', 'v1.0.0-beta', 'v0.10.0', 'v0.9.0', 'v1.0.0-alpha', 'v1.0.0-beta.alpha'];
  const sorted = tags.map(parseTag).sort(compareVersions).map((v) => v.tag);
  assert.deepEqual(sorted, ['v0.9.0', 'v0.10.0', 'v1.0.0-alpha', 'v1.0.0-beta', 'v1.0.0-beta.2', 'v1.0.0-beta.10', 'v1.0.0-beta.alpha', 'v1.0.0']);
});

test('patch bumps the newest stable release', () => {
  assert.deepEqual(release(['v1.0.0', 'v1.0.1'], 'patch'), { tag: 'v1.0.2', promoteFrom: null, previous: 'v1.0.1' });
});

test('minor and major reset the lower fields', () => {
  assert.equal(release(['v1.2.3'], 'minor').tag, 'v1.3.0');
  assert.equal(release(['v1.2.3'], 'major').tag, 'v2.0.0');
});

test('a beta starts at beta.1 for the bumped version', () => {
  assert.equal(release(['v1.0.0'], 'minor', true).tag, 'v1.1.0-beta.1');
});

test('a beta on a version that already has betas increments N', () => {
  const tags = ['v1.0.0', 'v1.1.0-beta.1', 'v1.1.0-beta.2'];
  assert.deepEqual(release(tags, 'minor', true), { tag: 'v1.1.0-beta.3', promoteFrom: null, previous: 'v1.1.0-beta.2' });
  assert.equal(release(tags, 'patch', true).tag, 'v1.1.0-beta.3');
});

test('beta numbering compares numerically, not lexically', () => {
  const tags = ['v1.0.0', ...Array.from({ length: 10 }, (_, i) => `v1.1.0-beta.${i + 1}`)];
  assert.equal(release(tags, 'minor', true).tag, 'v1.1.0-beta.11');
});

test('promote tags the newest prerelease commit as its stable version', () => {
  const tags = ['v1.0.0', 'v1.1.0-beta.1', 'v1.1.0-beta.2'];
  assert.deepEqual(release(tags, 'promote'), { tag: 'v1.1.0', promoteFrom: 'v1.1.0-beta.2', previous: 'v1.0.0' });
});

test('promote refuses when the newest prerelease already shipped stable', () => {
  assert.throws(() => release(['v1.1.0-beta.2', 'v1.1.0'], 'promote'), /v1\.1\.0 already exists/);
});

test('promote refuses beta and repositories without prereleases', () => {
  assert.throws(() => release(['v1.1.0-beta.2'], 'promote', true), /untick beta/);
  assert.throws(() => release(['v1.0.0'], 'promote'), /no prerelease/);
});

test('the first stable release after v0.8.0-beta', () => {
  assert.deepEqual(release(CURRENT, 'patch'), { tag: 'v0.8.0', promoteFrom: null, previous: 'v0.8.0-beta' });
  assert.equal(release(CURRENT, 'minor').tag, 'v0.8.0');
  assert.equal(release(CURRENT, 'major').tag, 'v1.0.0');
  assert.deepEqual(release(CURRENT, 'promote'), { tag: 'v0.8.0', promoteFrom: 'v0.8.0-beta', previous: 'v0.7.0-alpha' });
  assert.equal(release(CURRENT, 'patch', true).tag, 'v0.8.0-beta.1');
});

test('an explicit v1.0.0 on the current tags', () => {
  assert.deepEqual(release(CURRENT, 'explicit', false, 'v1.0.0'), { tag: 'v1.0.0', promoteFrom: null, previous: 'v0.8.0-beta' });
  assert.equal(release(CURRENT, 'explicit', false, '1.0.0').tag, 'v1.0.0');
  assert.equal(release(CURRENT, 'explicit', true, 'v1.0.0-beta.1').tag, 'v1.0.0-beta.1');
});

test('explicit versions are validated', () => {
  assert.throws(() => release(CURRENT, 'explicit', false, ''), /must be vMAJOR/);
  assert.throws(() => release(CURRENT, 'explicit', false, 'v1.0'), /must be vMAJOR/);
  assert.throws(() => release(CURRENT, 'explicit', true, 'v1.0.0'), /no prerelease suffix/);
  assert.throws(() => release(CURRENT, 'explicit', false, 'v1.0.0-rc.1'), /tick beta/);
  assert.throws(() => release(CURRENT, 'explicit', false, 'v0.7.0'), /newer than v0\.8\.0-beta/);
  assert.throws(() => release([...CURRENT, 'v1.0.0'], 'explicit', false, 'v1.0.0'), /already exists/);
  assert.throws(() => release(CURRENT, 'patch', false, 'v1.0.0'), /only used with bump=explicit/);
});

test('a release branch bumps from its own tags but stays unique across all tags', () => {
  assert.deepEqual(release(['v1.0.0', 'v1.1.0'], 'patch', false, '', ['v1.0.0']), { tag: 'v1.0.1', promoteFrom: null, previous: 'v1.0.0' });
  assert.throws(() => release(['v1.0.0', 'v1.1.0', 'v1.0.1'], 'patch', false, '', ['v1.0.0']), /v1\.0\.1 already exists/);
});
