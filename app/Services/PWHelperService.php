<?php

namespace App\Services;

class PWHelperService
{
    /** @var array<string, string> */
    public const PROJECT_API_FIELDS = [
        'iron_works' => 'Ironworks',
        'bauxite_works' => 'Bauxiteworks',
        'arms_stockpile' => 'Arms Stockpile',
        'emergency_gasoline_reserve' => 'Emergency Gasoline Reserve',
        'mass_irrigation' => 'Mass Irrigation',
        'international_trade_center' => 'International Trade Center',
        'missile_launch_pad' => 'Missile Launch Pad',
        'nuclear_research_facility' => 'Nuclear Research Facility',
        'iron_dome' => 'Iron Dome',
        'vital_defense_system' => 'Vital Defense System',
        'central_intelligence_agency' => 'Central Intelligence Agency',
        'center_for_civil_engineering' => 'Center for Civil Engineering',
        'propaganda_bureau' => 'Propaganda Bureau',
        'uranium_enrichment_program' => 'Uranium Enrichment Program',
        'urban_planning' => 'Urban Planning',
        'advanced_urban_planning' => 'Advanced Urban Planning',
        'space_program' => 'Space Program',
        'spy_satellite' => 'Spy Satellite',
        'moon_landing' => 'Moon Landing',
        'pirate_economy' => 'Pirate Economy',
        'recycling_initiative' => 'Recycling Initiative',
        'telecommunications_satellite' => 'Telecommunications Satellite',
        'green_technologies' => 'Green Technologies',
        'arable_land_agency' => 'Arable Land Agency',
        'clinical_research_center' => 'Clinical Research Center',
        'specialized_police_training_program' => 'Specialized Police Training Program',
        'advanced_engineering_corps' => 'Advanced Engineering Corps',
        'government_support_agency' => 'Government Support Agency',
        'research_and_development_center' => 'Research and Development Center',
        'activity_center' => 'Activity Center',
        'metropolitan_planning' => 'Metropolitan Planning',
        'military_salvage' => 'Military Salvage',
        'fallout_shelter' => 'Fallout Shelter',
        'bureau_of_domestic_affairs' => 'Bureau of Domestic Affairs',
        'advanced_pirate_economy' => 'Advanced Pirate Economy',
        'mars_landing' => 'Mars Landing',
        'surveillance_network' => 'Surveillance Network',
        'guiding_satellite' => 'Guiding Satellite',
        'nuclear_launch_facility' => 'Nuclear Launch Facility',
    ];

    /**
     * This is to work with the Project Bits field of the API
     * It's just an associative array to map each project to its bit position
     */
    public const PROJECTS = [
        'Ironworks' => 1 << 0,
        'Bauxiteworks' => 1 << 1,
        'Arms Stockpile' => 1 << 2,
        'Emergency Gasoline Reserve' => 1 << 3,
        'Mass Irrigation' => 1 << 4,
        'International Trade Center' => 1 << 5,
        'Missile Launch Pad' => 1 << 6,
        'Nuclear Research Facility' => 1 << 7,
        'Iron Dome' => 1 << 8,
        'Vital Defense System' => 1 << 9,
        'Central Intelligence Agency' => 1 << 10,
        'Center for Civil Engineering' => 1 << 11,
        'Propaganda Bureau' => 1 << 12,
        'Uranium Enrichment Program' => 1 << 13,
        'Urban Planning' => 1 << 14,
        'Advanced Urban Planning' => 1 << 15,
        'Space Program' => 1 << 16,
        'Spy Satellite' => 1 << 17,
        'Moon Landing' => 1 << 18,
        'Pirate Economy' => 1 << 19,
        'Recycling Initiative' => 1 << 20,
        'Telecommunications Satellite' => 1 << 21,
        'Green Technologies' => 1 << 22,
        'Arable Land Agency' => 1 << 23,
        'Clinical Research Center' => 1 << 24,
        'Specialized Police Training Program' => 1 << 25,
        'Advanced Engineering Corps' => 1 << 26,
        'Government Support Agency' => 1 << 27,
        'Research and Development Center' => 1 << 28,
        'Activity Center' => 1 << 29,
        'Metropolitan Planning' => 1 << 30,
        'Military Salvage' => 1 << 31,
        'Fallout Shelter' => 1 << 32,
        'Bureau of Domestic Affairs' => 1 << 33,
        'Advanced Pirate Economy' => 1 << 34,
        'Mars Landing' => 1 << 35,
        'Surveillance Network' => 1 << 36,
        'Guiding Satellite' => 1 << 37,
        'Nuclear Launch Facility' => 1 << 38,
    ];

    /**
     * @return array<int, string>
     */
    public static function projects(): array
    {
        $projects = self::PROJECTS;
        asort($projects);

        return array_keys($projects);
    }

    public static function getNationProjects(int|string|null $projectBits): array
    {
        if ($projectBits === null || $projectBits === '') {
            return [];
        }

        $bitmask = self::projectBitmask($projectBits);
        $ownedProjects = [];

        foreach (self::PROJECTS as $project => $bit) {
            if ($bitmask & $bit) { // Bitwise AND to check if project is owned
                $ownedProjects[] = $project;
            }
        }

        return $ownedProjects;
    }

    /**
     * Reconcile the aggregate bitmask with explicit project flags returned by the API.
     *
     * @param  array<string, mixed>  $projectOwnership
     */
    public static function reconcileProjectBits(int|string|null $projectBits, array $projectOwnership): ?string
    {
        $bitmask = self::projectBitmask($projectBits);
        $hasExplicitOwnership = false;

        foreach (self::PROJECT_API_FIELDS as $field => $project) {
            if (! array_key_exists($field, $projectOwnership) || $projectOwnership[$field] === null) {
                continue;
            }

            $hasExplicitOwnership = true;
            $ownsProject = filter_var($projectOwnership[$field], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            if ($ownsProject === null) {
                continue;
            }

            $projectBit = self::PROJECTS[$project];
            $bitmask = $ownsProject ? $bitmask | $projectBit : $bitmask & ~$projectBit;
        }

        if (($projectBits === null || $projectBits === '') && ! $hasExplicitOwnership) {
            return null;
        }

        return (string) $bitmask;
    }

    private static function projectBitmask(int|string|null $projectBits): int
    {
        if (is_int($projectBits)) {
            return max(0, $projectBits);
        }

        $projectBits = trim((string) $projectBits);

        if ($projectBits === '' || preg_match('/^\d+$/', $projectBits) !== 1) {
            return 0;
        }

        $maximumDecimalDigits = strlen((string) array_sum(self::PROJECTS));
        $isUnambiguousBinary = strlen($projectBits) > $maximumDecimalDigits
            && preg_match('/^[01]+$/', $projectBits) === 1;

        return $isUnambiguousBinary ? (int) bindec($projectBits) : max(0, (int) $projectBits);
    }

    /**
     * @return string[]
     */
    public static function resources(bool $includeMoney = true, bool $includeCredits = false): array
    {
        $resources = [
            'coal',
            'oil',
            'uranium',
            'iron',
            'bauxite',
            'lead',
            'gasoline',
            'munitions',
            'steel',
            'aluminum',
            'food',
        ];

        if ($includeMoney) {
            array_unshift($resources, 'money');
        }

        if ($includeCredits) {
            $resources[] = 'credits';
        }

        return $resources;
    }
}
