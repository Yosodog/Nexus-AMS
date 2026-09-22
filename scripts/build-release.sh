#!/usr/bin/env bash
set -Eeuo pipefail

release_tag=${1:-${GITHUB_REF_NAME:-}}

if [[ ! ${release_tag} =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo 'usage: scripts/build-release.sh vMAJOR.MINOR.PATCH' >&2
  exit 1
fi

project_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
build_root=$(mktemp -d)
trap 'rm -rf "${build_root}"' EXIT

mkdir -p "${project_root}/dist" "${build_root}/app"
git -C "${project_root}" archive HEAD | tar -x -C "${build_root}/app"

(
  cd "${build_root}/app"
  composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader
  npm ci --ignore-scripts
  npm run build
  rm -rf node_modules tests .github .codex docs dist storage bootstrap/cache
  rm -f .env .env.* auth.json
  printf '{"schema_version":1,"component":"nexus-core","release":"%s"}\n' "${release_tag}" > nexus-release.json
)

if tar --help 2>&1 | grep -q -- '--sort'; then
  tar --sort=name --mtime='UTC 1970-01-01' --owner=0 --group=0 --numeric-owner \
    -czf "${project_root}/dist/nexus-core.tar.gz" -C "${build_root}/app" .
else
  COPYFILE_DISABLE=1 tar -czf "${project_root}/dist/nexus-core.tar.gz" -C "${build_root}/app" .
fi

echo "Created dist/nexus-core.tar.gz for ${release_tag}"
