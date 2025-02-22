<?php

namespace App\Services;

class PWHelperService
{

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
     * @param  int  $projectBits
     *
     * @return array
     */
    public static function getNationProjects(int $projectBits): array
    {
        $ownedProjects = [];

        foreach (self::PROJECTS as $project => $bit) {
            if ($projectBits & $bit) { // Bitwise AND to check if project is owned
                $ownedProjects[] = $project;
            }
        }

        return $ownedProjects;
    }

}
