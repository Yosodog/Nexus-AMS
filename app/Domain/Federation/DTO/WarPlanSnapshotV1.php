<?php

namespace App\Domain\Federation\DTO;

use App\Domain\Federation\Support\CanonicalJson;
use App\Domain\Federation\Support\StrictJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class WarPlanSnapshotV1
{
    public const SCHEMA = 'milcom.war-plan-snapshot/1.0';

    /** @var list<string> */
    private const FIELDS = [
        'schema',
        'publication_id',
        'version_id',
        'version',
        'revision',
        'source_installation_id',
        'source_alliance_id',
        'coalition_id',
        'roster_revision',
        'source_generation',
        'published_at',
        'expires_at',
        'recipient_installation_id',
        'title',
        'wave_label',
        'recipient_instructions',
        'targets',
    ];

    /**
     * @param  list<WarPlanTargetV1>  $targets
     */
    public function __construct(
        public string $publicationId,
        public string $versionId,
        public int $version,
        public int $revision,
        public string $sourceInstallationId,
        public int $sourceAllianceId,
        public string $coalitionId,
        public int $rosterRevision,
        public int $sourceGeneration,
        public CarbonImmutable $publishedAt,
        public CarbonImmutable $expiresAt,
        public string $recipientInstallationId,
        public string $title,
        public string $waveLabel,
        public string $recipientInstructions,
        public array $targets,
    ) {
        foreach ([$publicationId, $versionId, $sourceInstallationId, $coalitionId, $recipientInstallationId] as $id) {
            if (! Str::isUlid($id)) {
                throw new InvalidArgumentException('War-plan snapshot contains an invalid ULID.');
            }
        }

        if ($version < 1 || $revision < 1 || $rosterRevision < 1 || $sourceGeneration < 1 || $sourceAllianceId < 1) {
            throw new InvalidArgumentException('War-plan snapshot revisions and identifiers must be positive.');
        }

        if ($targets === [] || count($targets) > (int) config('federation.limits.targets_per_publication', 500)) {
            throw new InvalidArgumentException('War-plan snapshot target count is invalid.');
        }

        foreach ($targets as $target) {
            if (! $target instanceof WarPlanTargetV1) {
                throw new InvalidArgumentException('War-plan snapshot targets are invalid.');
            }
        }

        if (Str::length($title) < 1 || Str::length($title) > 255 || Str::length($waveLabel) > 100) {
            throw new InvalidArgumentException('War-plan snapshot title or wave label is invalid.');
        }

        if (Str::length($recipientInstructions)
            > (int) config('federation.limits.recipient_instructions_characters', 1000)) {
            throw new InvalidArgumentException('Recipient instructions exceed the protocol limit.');
        }

        if (! $expiresAt->isAfter($publishedAt)) {
            throw new InvalidArgumentException('War-plan snapshot expiry must follow publication.');
        }
    }

    public static function fromJson(string $json): self
    {
        return self::fromArray(StrictJson::decodeObject($json));
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        StrictJson::rejectUnknown($data, self::FIELDS);
        StrictJson::requireProperties($data, self::FIELDS);

        if ($data['schema'] !== self::SCHEMA || ! is_array($data['targets']) || ! array_is_list($data['targets'])) {
            throw new InvalidArgumentException('Unsupported war-plan snapshot schema.');
        }

        foreach (['publication_id', 'version_id', 'source_installation_id', 'coalition_id', 'published_at',
            'expires_at', 'recipient_installation_id', 'title', 'wave_label', 'recipient_instructions'] as $field) {
            if (! is_string($data[$field])) {
                throw new InvalidArgumentException('War-plan snapshot has invalid field types.');
            }
        }

        foreach (['version', 'revision', 'source_alliance_id', 'roster_revision', 'source_generation'] as $field) {
            if (! is_int($data[$field])) {
                throw new InvalidArgumentException('War-plan snapshot has invalid numeric fields.');
            }
        }

        return new self(
            publicationId: $data['publication_id'],
            versionId: $data['version_id'],
            version: $data['version'],
            revision: $data['revision'],
            sourceInstallationId: $data['source_installation_id'],
            sourceAllianceId: $data['source_alliance_id'],
            coalitionId: $data['coalition_id'],
            rosterRevision: $data['roster_revision'],
            sourceGeneration: $data['source_generation'],
            publishedAt: CarbonImmutable::parse($data['published_at']),
            expiresAt: CarbonImmutable::parse($data['expires_at']),
            recipientInstallationId: $data['recipient_installation_id'],
            title: $data['title'],
            waveLabel: $data['wave_label'],
            recipientInstructions: $data['recipient_instructions'],
            targets: array_map(
                function (mixed $target): WarPlanTargetV1 {
                    if (! is_array($target) || array_is_list($target)) {
                        throw new InvalidArgumentException('War-plan target must be an object.');
                    }

                    return WarPlanTargetV1::fromArray($target);
                },
                $data['targets']
            ),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'publication_id' => $this->publicationId,
            'version_id' => $this->versionId,
            'version' => $this->version,
            'revision' => $this->revision,
            'source_installation_id' => $this->sourceInstallationId,
            'source_alliance_id' => $this->sourceAllianceId,
            'coalition_id' => $this->coalitionId,
            'roster_revision' => $this->rosterRevision,
            'source_generation' => $this->sourceGeneration,
            'published_at' => $this->publishedAt->utc()->toIso8601String(),
            'expires_at' => $this->expiresAt->utc()->toIso8601String(),
            'recipient_installation_id' => $this->recipientInstallationId,
            'title' => $this->title,
            'wave_label' => $this->waveLabel,
            'recipient_instructions' => $this->recipientInstructions,
            'targets' => array_map(
                fn (WarPlanTargetV1 $target): array => $target->toArray(),
                $this->targets
            ),
        ];
    }

    public function toJson(): string
    {
        return CanonicalJson::encode($this->toArray());
    }

    public function hash(): string
    {
        return hash('sha256', $this->toJson());
    }
}
