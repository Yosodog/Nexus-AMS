<?php

declare(strict_types=1);

try {
    $options = getopt('', ['output:', 'version:', 'commit:', 'base-image:', 'dpkg-status::']);

    if (! is_array($options)) {
        throw new RuntimeException('Invalid SBOM options.');
    }

    $output = option($options, 'output');
    $version = option($options, 'version');
    $commit = option($options, 'commit');
    $baseImage = option($options, 'base-image');
    $dpkgStatus = optionalOption($options, 'dpkg-status') ?? '/var/lib/dpkg/status';

    if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,63}\z/D', $version) !== 1
        || preg_match('/\A[a-f0-9]{40}\z/D', $commit) !== 1) {
        throw new RuntimeException('Invalid build identity.');
    }

    $projectRoot = dirname(__DIR__, 2);
    $components = [];

    addBaseImageComponent($components, $baseImage);
    addComposerComponents($components, decodeJson($projectRoot.'/composer.lock'));
    addNpmComponents($components, decodeJson($projectRoot.'/package-lock.json'));
    addPhpRuntimeComponents($components);

    if (is_file($dpkgStatus) && is_readable($dpkgStatus)) {
        addDebianComponents($components, (string) file_get_contents($dpkgStatus));
    }

    ksort($components, SORT_STRING);
    $componentList = array_values($components);
    $serialSeed = hash('sha256', json_encode([
        'application_version' => $version,
        'commit' => $commit,
        'components' => array_column($componentList, 'bom-ref'),
    ], JSON_THROW_ON_ERROR));

    $bom = [
        'bomFormat' => 'CycloneDX',
        'specVersion' => '1.6',
        'serialNumber' => 'urn:uuid:'.deterministicUuid($serialSeed),
        'version' => 1,
        'metadata' => [
            'component' => [
                'type' => 'application',
                'bom-ref' => 'pkg:generic/nexus-ams@'.rawurlencode($version),
                'name' => 'Nexus AMS',
                'version' => $version,
                'licenses' => [['license' => ['id' => 'GPL-3.0-only']]],
                'properties' => [
                    ['name' => 'org.opencontainers.image.revision', 'value' => $commit],
                ],
            ],
        ],
        'components' => $componentList,
    ];

    $directory = dirname($output);

    if ((! is_dir($directory) && ! mkdir($directory, 0755, true)) || ! is_writable($directory)) {
        throw new RuntimeException('SBOM output directory is unavailable.');
    }

    $encoded = json_encode(
        $bom,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    )."\n";

    if (file_put_contents($output, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('SBOM output could not be written.');
    }
} catch (Throwable) {
    fwrite(STDERR, 'CycloneDX SBOM generation failed.'.PHP_EOL);
    exit(1);
}

/** @param array<string, mixed> $options */
function option(array $options, string $name): string
{
    $value = $options[$name] ?? null;

    if (! is_string($value) || $value === '') {
        throw new RuntimeException('A required SBOM option is missing.');
    }

    return $value;
}

/** @param array<string, mixed> $options */
function optionalOption(array $options, string $name): ?string
{
    $value = $options[$name] ?? null;

    return is_string($value) && $value !== '' ? $value : null;
}

/** @param array<string, array<string, mixed>> $components */
function addBaseImageComponent(array &$components, string $reference): void
{
    if (preg_match(
        '/\A(?<repository>[a-z0-9._\/-]+):(?<tag>[a-z0-9._-]+)@sha256:(?<digest>[a-f0-9]{64})\z/D',
        $reference,
        $matches,
    ) !== 1) {
        throw new RuntimeException('The base image reference is invalid.');
    }

    $repository = $matches['repository'];
    $tag = $matches['tag'];
    $digest = $matches['digest'];
    $name = basename($repository);
    $purl = sprintf(
        'pkg:oci/%s@%s?repository_url=%s&tag=%s',
        rawurlencode($name),
        rawurlencode('sha256:'.$digest),
        rawurlencode($repository),
        rawurlencode($tag),
    );

    $components[$purl] = [
        'type' => 'container',
        'bom-ref' => $purl,
        'name' => $name,
        'version' => $tag,
        'purl' => $purl,
        'hashes' => [['alg' => 'SHA-256', 'content' => $digest]],
        'scope' => 'required',
        'properties' => [
            ['name' => 'nexus.dependency.ecosystem', 'value' => 'oci'],
            ['name' => 'nexus.dependency.scope', 'value' => 'runtime-base'],
            ['name' => 'nexus.oci.reference', 'value' => $reference],
        ],
    ];
}

/** @return array<string, mixed> */
function decodeJson(string $path): array
{
    if (! is_file($path) || ! is_readable($path)) {
        throw new RuntimeException('A dependency lock file is unavailable.');
    }

    try {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('A dependency lock file is invalid.');
    }

    if (! is_array($decoded)) {
        throw new RuntimeException('A dependency lock file has an invalid root.');
    }

    return $decoded;
}

/**
 * @param  array<string, array<string, mixed>>  $components
 * @param  array<string, mixed>  $lock
 */
function addComposerComponents(array &$components, array $lock): void
{
    $packages = $lock['packages'] ?? null;

    if (! is_array($packages)) {
        throw new RuntimeException('The Composer production package list is unavailable.');
    }

    foreach ($packages as $package) {
        if (! is_array($package)) {
            continue;
        }

        $name = $package['name'] ?? null;
        $version = $package['version'] ?? null;

        if (! is_string($name) || ! is_string($version) || $name === '' || $version === '') {
            continue;
        }

        $purl = 'pkg:composer/'.purlPath($name).'@'.rawurlencode($version);
        $component = [
            'type' => 'library',
            'bom-ref' => $purl,
            'name' => $name,
            'version' => $version,
            'purl' => $purl,
            'scope' => 'required',
            'properties' => [
                ['name' => 'nexus.dependency.ecosystem', 'value' => 'composer'],
                ['name' => 'nexus.dependency.scope', 'value' => 'runtime'],
            ],
        ];
        $licenses = licenseEntries($package['license'] ?? null);

        if ($licenses !== []) {
            $component['licenses'] = $licenses;
        }

        $shasum = $package['dist']['shasum'] ?? null;

        if (is_string($shasum) && preg_match('/\A[a-f0-9]{40}\z/D', $shasum) === 1) {
            $component['hashes'] = [['alg' => 'SHA-1', 'content' => $shasum]];
        }

        $components[$purl] = $component;
    }
}

/**
 * @param  array<string, array<string, mixed>>  $components
 * @param  array<string, mixed>  $lock
 */
function addNpmComponents(array &$components, array $lock): void
{
    $packages = $lock['packages'] ?? null;

    if (! is_array($packages)) {
        throw new RuntimeException('The npm package list is unavailable.');
    }

    foreach ($packages as $path => $package) {
        if ($path === '' || ! is_string($path) || ! is_array($package)) {
            continue;
        }

        $name = $package['name'] ?? npmNameFromPath($path);
        $version = $package['version'] ?? null;

        if (! is_string($name) || ! is_string($version) || $name === '' || $version === '') {
            continue;
        }

        $purl = 'pkg:npm/'.purlPath($name).'@'.rawurlencode($version);
        $component = [
            'type' => 'library',
            'bom-ref' => $purl,
            'name' => $name,
            'version' => $version,
            'purl' => $purl,
            'scope' => 'required',
            'properties' => [
                ['name' => 'nexus.dependency.ecosystem', 'value' => 'npm'],
                ['name' => 'nexus.dependency.scope', 'value' => 'compiled-frontend'],
            ],
        ];
        $license = $package['license'] ?? null;

        if (is_string($license) && $license !== '') {
            $component['licenses'] = [['license' => ['name' => $license]]];
        }

        $integrity = $package['integrity'] ?? null;

        if (is_string($integrity) && str_starts_with($integrity, 'sha512-')) {
            $binaryHash = base64_decode(substr($integrity, 7), true);

            if (is_string($binaryHash)) {
                $component['hashes'] = [[
                    'alg' => 'SHA-512',
                    'content' => bin2hex($binaryHash),
                ]];
            }
        }

        $components[$purl] = $component;
    }
}

/** @param array<string, array<string, mixed>> $components */
function addDebianComponents(array &$components, string $status): void
{
    foreach (preg_split('/\R\R+/', trim($status)) ?: [] as $paragraph) {
        if (preg_match('/^Status:\s+install ok installed$/m', $paragraph) !== 1
            || preg_match('/^Package:\s*(\S+)$/m', $paragraph, $packageMatch) !== 1
            || preg_match('/^Version:\s*(\S+)$/m', $paragraph, $versionMatch) !== 1) {
            continue;
        }

        $name = $packageMatch[1];
        $version = $versionMatch[1];
        $architecture = preg_match('/^Architecture:\s*(\S+)$/m', $paragraph, $architectureMatch) === 1
            ? $architectureMatch[1]
            : 'unknown';
        $purl = sprintf(
            'pkg:deb/debian/%s@%s?arch=%s',
            rawurlencode($name),
            rawurlencode($version),
            rawurlencode($architecture),
        );

        $components[$purl] = [
            'type' => 'library',
            'bom-ref' => $purl,
            'name' => $name,
            'version' => $version,
            'purl' => $purl,
            'scope' => 'required',
            'properties' => [
                ['name' => 'nexus.dependency.ecosystem', 'value' => 'debian'],
                ['name' => 'nexus.dependency.scope', 'value' => 'runtime-image'],
            ],
        ];
    }
}

/** @param array<string, array<string, mixed>> $components */
function addPhpRuntimeComponents(array &$components): void
{
    $phpPurl = 'pkg:generic/php@'.rawurlencode(PHP_VERSION);
    $components[$phpPurl] = [
        'type' => 'framework',
        'bom-ref' => $phpPurl,
        'name' => 'PHP',
        'version' => PHP_VERSION,
        'purl' => $phpPurl,
        'scope' => 'required',
        'properties' => [
            ['name' => 'nexus.dependency.ecosystem', 'value' => 'php-runtime'],
            ['name' => 'nexus.dependency.scope', 'value' => 'runtime-image'],
        ],
    ];

    foreach (get_loaded_extensions() as $extension) {
        $version = phpversion($extension);

        if (! is_string($version) || $version === '') {
            $version = PHP_VERSION;
        }

        $normalizedName = strtolower(str_replace(' ', '-', $extension));
        $purl = sprintf(
            'pkg:generic/php-extension-%s@%s',
            rawurlencode($normalizedName),
            rawurlencode($version),
        );
        $components[$purl] = [
            'type' => 'library',
            'bom-ref' => $purl,
            'name' => $extension,
            'version' => $version,
            'purl' => $purl,
            'scope' => 'required',
            'properties' => [
                ['name' => 'nexus.dependency.ecosystem', 'value' => 'php-extension'],
                ['name' => 'nexus.dependency.scope', 'value' => 'runtime-image'],
            ],
        ];
    }
}

/** @return list<array{license: array{name: string}}> */
function licenseEntries(mixed $licenses): array
{
    if (! is_array($licenses)) {
        return [];
    }

    $entries = [];

    foreach ($licenses as $license) {
        if (is_string($license) && $license !== '') {
            $entries[] = ['license' => ['name' => $license]];
        }
    }

    return $entries;
}

function npmNameFromPath(string $path): string
{
    $marker = 'node_modules/';
    $position = strrpos($path, $marker);

    return $position === false ? '' : substr($path, $position + strlen($marker));
}

function purlPath(string $name): string
{
    return implode('/', array_map(rawurlencode(...), explode('/', $name)));
}

function deterministicUuid(string $hex): string
{
    $value = substr(str_pad($hex, 32, '0'), 0, 32);
    $value[12] = '5';
    $value[16] = dechex((hexdec($value[16]) & 0x3) | 0x8);

    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($value, 0, 8),
        substr($value, 8, 4),
        substr($value, 12, 4),
        substr($value, 16, 4),
        substr($value, 20, 12),
    );
}
