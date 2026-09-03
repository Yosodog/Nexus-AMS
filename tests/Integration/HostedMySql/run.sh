#!/usr/bin/env bash

set -Eeuo pipefail

script_directory="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repository_root="$(cd "${script_directory}/../../.." && pwd)"
runtime_directory="$(mktemp -d "${repository_root}/storage/framework/testing/nexus-ams-hosted-mysql.XXXXXXXX")"
fixture_id="$(php -r 'echo bin2hex(random_bytes(8));')"
compose_project="nxa2_${fixture_id}"
root_password_file="${runtime_directory}/mysql-root-password"
compose_started=0

if [[ ! "${compose_project}" =~ ^nxa2_[0-9a-f]{16}$ ]]; then
    echo "The generated hosted MySQL Compose project name was invalid." >&2
    exit 1
fi

umask 077
php -r 'file_put_contents($argv[1], bin2hex(random_bytes(32)));' "${root_password_file}"
chmod 0600 "${root_password_file}"

cleanup() {
    local exit_status=$?
    local cleanup_status=0
    local leftovers

    trap - EXIT

    if ((compose_started == 1)); then
        if ! docker compose --file "${script_directory}/compose.yaml" --project-name "${compose_project}" down --volumes --remove-orphans >/dev/null; then
            echo "The hosted MySQL Compose topology did not clean up successfully." >&2
            cleanup_status=1
        fi

        if ! docker compose --file "${script_directory}/compose.yaml" --project-name "${compose_project}" down --volumes --remove-orphans >/dev/null; then
            echo "The hosted MySQL Compose cleanup was not idempotent." >&2
            cleanup_status=1
        fi

        leftovers="$(docker ps -a --filter "label=com.docker.compose.project=${compose_project}" --quiet)"
        if [[ -n "${leftovers}" ]]; then
            echo "The hosted MySQL container inventory was not empty." >&2
            cleanup_status=1
        fi

        leftovers="$(docker volume ls --filter "label=com.docker.compose.project=${compose_project}" --quiet)"
        if [[ -n "${leftovers}" ]]; then
            echo "The hosted MySQL volume inventory was not empty." >&2
            cleanup_status=1
        fi

        leftovers="$(docker network ls --filter "label=com.docker.compose.project=${compose_project}" --quiet)"
        if [[ -n "${leftovers}" ]]; then
            echo "The hosted MySQL network inventory was not empty." >&2
            cleanup_status=1
        fi
    fi

    if [[ -f "${root_password_file}" ]]; then
        if ! php -r '$path = $argv[1]; $length = filesize($path); if (is_int($length) && $length > 0) { file_put_contents($path, random_bytes($length), LOCK_EX); }' "${root_password_file}"; then
            echo "The hosted MySQL runtime secret could not be overwritten." >&2
            cleanup_status=1
        fi
    fi

    rm -f -- "${root_password_file}"

    if ! rmdir -- "${runtime_directory}"; then
        echo "The hosted MySQL runtime secret directory was not empty after cleanup." >&2
        cleanup_status=1
    fi

    if ((exit_status == 0 && cleanup_status != 0)); then
        exit_status="${cleanup_status}"
    fi

    exit "${exit_status}"
}

trap cleanup EXIT

export NEXUS_AMS_PHASE2_MYSQL_ROOT_PASSWORD_FILE="${root_password_file}"
compose_started=1
docker compose \
    --file "${script_directory}/compose.yaml" \
    --project-name "${compose_project}" \
    up --detach --wait --wait-timeout 180

mysql_port_output="$(docker compose \
    --file "${script_directory}/compose.yaml" \
    --project-name "${compose_project}" \
    port mysql 3306)"
mysql_port="${mysql_port_output##*:}"
mysql_container_id="$(docker compose \
    --file "${script_directory}/compose.yaml" \
    --project-name "${compose_project}" \
    ps --quiet mysql)"

if [[ ! "${mysql_port}" =~ ^[0-9]{1,5}$ ]] || ((mysql_port < 1 || mysql_port > 65535)); then
    echo "Docker did not publish a valid loopback hosted MySQL port." >&2
    exit 1
fi

if [[ ! "${mysql_container_id}" =~ ^[0-9a-f]{64}$ ]]; then
    echo "Docker did not return the exact hosted MySQL container identifier." >&2
    exit 1
fi

cd "${repository_root}"

DB_CONNECTION=mysql \
DB_HOST=127.0.0.1 \
DB_PORT="${mysql_port}" \
DB_DATABASE=nexus_testing \
DB_USERNAME=root \
DB_PASSWORD="$(< "${root_password_file}")" \
php artisan test --compact \
    tests/Integration/HostedFreshMigrationTest.php \
    tests/Integration/StandaloneFreshMigrationTest.php \
    tests/Integration/RuntimeUpgradeMigrationTest.php \
    tests/Integration/WorldReferenceTest.php
