// Writes the nexus-release.json manifest that the Nexus updater reads.
//
//   node scripts/release-manifest.mjs <component> <release> <requires.json> [artifact]
//
// Without an artifact it prints the copy embedded in the release tarball.
// With one it prints the copy published next to the tarball, which also
// carries the tarball's SHA-256 digest.
import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';

const COMPONENTS = ['nexus-core', 'nexus-subs', 'nexus-discord'];
const REQUIREMENTS = [...COMPONENTS, 'nexus-updater'];
const TAG_PATTERN = /^v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-([0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*))?$/;

function fail(message) {
  console.error(`release-manifest: ${message}`);
  process.exit(1);
}

function validTag(tag) {
  const match = TAG_PATTERN.exec(tag);
  if (!match || tag.length > 80) {
    return false;
  }
  const prerelease = match[4];
  return prerelease === undefined || prerelease.split('.').every((part) => !/^0\d+$/.test(part));
}

const [component, release, requiresPath, artifactPath] = process.argv.slice(2);
if (!COMPONENTS.includes(component)) {
  fail(`unknown component ${component}`);
}
if (!validTag(release)) {
  fail(`release tag ${release} must be vMAJOR.MINOR.PATCH with an optional -prerelease suffix`);
}

let requires = {};
if (requiresPath && existsSync(requiresPath)) {
  requires = JSON.parse(readFileSync(requiresPath, 'utf8'));
}
if (requires === null || typeof requires !== 'object' || Array.isArray(requires)) {
  fail(`${requiresPath} must contain a JSON object`);
}
for (const [name, minimum] of Object.entries(requires)) {
  if (!REQUIREMENTS.includes(name) || name === component) {
    fail(`${requiresPath} lists an unsupported requirement ${name}`);
  }
  if (typeof minimum !== 'string' || !validTag(minimum)) {
    fail(`${requiresPath} requirement ${name} must be a release tag such as v1.2.0`);
  }
}

const manifest = { schema_version: 2, component, release };
const names = Object.keys(requires).sort();
if (names.length > 0) {
  manifest.requires = Object.fromEntries(names.map((name) => [name, requires[name]]));
}
if (artifactPath) {
  manifest.artifact_sha256 = createHash('sha256').update(readFileSync(artifactPath)).digest('hex');
}
process.stdout.write(`${JSON.stringify(manifest)}\n`);
