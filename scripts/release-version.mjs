// Works out the tag and commit for the next release from the repository's v* tags.
//
//   node scripts/release-version.mjs <bump> <beta> [version] [sha]
//   node --test scripts/*.test.mjs
//
// bump is patch, minor, major, promote, or explicit (which needs version).
// Prints key=value lines (tag, sha, previous, prerelease) for $GITHUB_OUTPUT.
// Bumps start from the newest tag reachable from sha, so a release/* branch
// continues its own line; uniqueness is checked against every tag.
import { execFileSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';

const TAG_PATTERN = /^v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-([0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*))?$/;
export const BUMPS = ['patch', 'minor', 'major', 'promote', 'explicit'];

export function parseTag(tag) {
  const match = TAG_PATTERN.exec(tag);
  if (!match) {
    return null;
  }
  const prerelease = match[4] === undefined ? [] : match[4].split('.');
  if (prerelease.some((part) => /^0\d+$/.test(part))) {
    return null;
  }
  return { tag, core: [Number(match[1]), Number(match[2]), Number(match[3])], prerelease };
}

function compareIdentifiers(a, b) {
  const aNumeric = /^\d+$/.test(a);
  const bNumeric = /^\d+$/.test(b);
  if (aNumeric && bNumeric) {
    return Number(a) - Number(b);
  }
  if (aNumeric !== bNumeric) {
    return aNumeric ? -1 : 1;
  }
  return a < b ? -1 : a > b ? 1 : 0;
}

export function compareVersions(a, b) {
  for (let i = 0; i < 3; i++) {
    if (a.core[i] !== b.core[i]) {
      return a.core[i] - b.core[i];
    }
  }
  if (a.prerelease.length === 0 || b.prerelease.length === 0) {
    return b.prerelease.length - a.prerelease.length;
  }
  for (let i = 0; i < Math.min(a.prerelease.length, b.prerelease.length); i++) {
    const order = compareIdentifiers(a.prerelease[i], b.prerelease[i]);
    if (order !== 0) {
      return order;
    }
  }
  return a.prerelease.length - b.prerelease.length;
}

function sortedVersions(tags) {
  return tags.map(parseTag).filter(Boolean).sort(compareVersions);
}

function newest(versions, predicate = () => true) {
  return versions.filter(predicate).at(-1) ?? null;
}

function coreTag([major, minor, patch]) {
  return `v${major}.${minor}.${patch}`;
}

// Same rules as npm's semver.inc: bumping a prerelease to the level it is
// already a prerelease of releases that version instead of skipping past it.
function bumpCore(base, bump) {
  if (base === null) {
    return { patch: [0, 0, 1], minor: [0, 1, 0], major: [1, 0, 0] }[bump];
  }
  const [major, minor, patch] = base.core;
  const pre = base.prerelease.length > 0;
  if (bump === 'major') {
    return pre && minor === 0 && patch === 0 ? [major, 0, 0] : [major + 1, 0, 0];
  }
  if (bump === 'minor') {
    return pre && patch === 0 ? [major, minor, 0] : [major, minor + 1, 0];
  }
  return pre ? [major, minor, patch] : [major, minor, patch + 1];
}

function nextBeta(core, versions) {
  const numbers = versions
    .filter((v) => v.core.every((part, i) => part === core[i]))
    .filter((v) => v.prerelease.length === 2 && v.prerelease[0] === 'beta' && /^\d+$/.test(v.prerelease[1]))
    .map((v) => Number(v.prerelease[1]));
  return `${coreTag(core)}-beta.${numbers.length > 0 ? Math.max(...numbers) + 1 : 1}`;
}

/**
 * @param {object} options
 * @param {string[]} options.tags every v* tag in the repository
 * @param {string[]} options.reachable tags reachable from the commit being released
 * @param {string} options.bump one of BUMPS
 * @param {boolean} options.beta publish a -beta.N prerelease
 * @param {string} [options.version] explicit tag for bump=explicit
 * @returns {{tag: string, promoteFrom: string|null, previous: string|null}}
 */
export function nextRelease({ tags, reachable, bump, beta, version = '' }) {
  if (!BUMPS.includes(bump)) {
    throw new Error(`bump must be one of ${BUMPS.join(', ')}`);
  }
  if (version !== '' && bump !== 'explicit') {
    throw new Error('version is only used with bump=explicit');
  }
  const all = sortedVersions(tags);
  const existing = new Set(all.map((v) => v.tag));
  const base = newest(sortedVersions(reachable));
  let tag;
  let promoteFrom = null;

  if (bump === 'promote') {
    if (beta) {
      throw new Error('promote publishes a stable release; untick beta');
    }
    const source = newest(all, (v) => v.prerelease.length > 0);
    if (source === null) {
      throw new Error('there is no prerelease to promote');
    }
    tag = coreTag(source.core);
    promoteFrom = source.tag;
  } else if (bump === 'explicit') {
    const normalized = version.startsWith('v') ? version : `v${version}`;
    const parsed = parseTag(normalized);
    if (parsed === null) {
      throw new Error(`version ${version} must be vMAJOR.MINOR.PATCH with an optional -prerelease suffix`);
    }
    if (beta !== parsed.prerelease.length > 0) {
      throw new Error(beta ? `beta is ticked but ${normalized} has no prerelease suffix` : `${normalized} is a prerelease; tick beta`);
    }
    if (existing.has(normalized)) {
      throw new Error(`${normalized} already exists`);
    }
    if (base !== null && compareVersions(parsed, base) <= 0) {
      throw new Error(`${normalized} must be newer than ${base.tag}`);
    }
    tag = normalized;
  } else {
    const core = bumpCore(base, bump);
    tag = beta ? nextBeta(core, all) : coreTag(core);
  }

  if (existing.has(tag)) {
    throw new Error(`${tag} already exists`);
  }

  const target = parseTag(tag);
  const older = (v) => compareVersions(v, target) < 0 && v.tag !== promoteFrom;
  const reachableVersions = sortedVersions(reachable).filter(older);
  const previous = target.prerelease.length > 0
    ? newest(reachableVersions)
    : newest(reachableVersions, (v) => v.prerelease.length === 0) ?? newest(reachableVersions);

  return { tag, promoteFrom, previous: previous?.tag ?? null };
}

function git(...args) {
  return execFileSync('git', args, { encoding: 'utf8' }).split('\n').filter(Boolean);
}

if (import.meta.url === pathToFileURL(process.argv[1]).href) {
  const [bump, beta, version = '', head = 'HEAD'] = process.argv.slice(2);
  try {
    if (!['true', 'false'].includes(beta)) {
      throw new Error('beta must be true or false');
    }
    const tags = git('tag', '--list', 'v*');
    const isBeta = beta === 'true';
    let sha = git('rev-parse', '--verify', `${head}^{commit}`)[0];
    let reachable = git('tag', '--list', 'v*', '--merged', sha);
    if (bump === 'promote') {
      const source = nextRelease({ tags, reachable: tags, bump, beta: isBeta }).promoteFrom;
      const sourceSha = git('rev-parse', '--verify', `${source}^{commit}`)[0];
      try {
        execFileSync('git', ['merge-base', '--is-ancestor', sourceSha, sha]);
      } catch {
        throw new Error(`${source} (${sourceSha}) is not on this branch`);
      }
      sha = sourceSha;
      reachable = git('tag', '--list', 'v*', '--merged', sha);
    }
    const release = nextRelease({ tags, reachable, bump, beta: isBeta, version });
    const lines = {
      tag: release.tag,
      sha,
      previous: release.previous ?? '',
      prerelease: String(release.tag.includes('-')),
      promote_from: release.promoteFrom ?? '',
    };
    process.stdout.write(Object.entries(lines).map(([key, value]) => `${key}=${value}\n`).join(''));
  } catch (error) {
    console.error(`release-version: ${error.message}`);
    process.exit(1);
  }
}
