<?php

namespace App\Services;

use App\Models\Nations;
use Illuminate\Validation\ValidationException;

class NationEligibilityValidator
{

    protected Nations $nation;

    public function __construct(Nations $nation)
    {
        $this->nation = $nation;
    }

    /**
     * Validate alliance membership.
     *
     * @throws ValidationException
     */
    public function validateAllianceMembership(): void
    {
        if ($this->nation->alliance_id != env("PW_ALLIANCE_ID")) {
            throw ValidationException::withMessages([
                'alliance' => 'You are not a member of the required alliance.',
            ]);
        }

        if ($this->nation->alliance_position == 'APPLICANT') {
            throw ValidationException::withMessages([
                'alliance' => 'Applicants are not eligible for financial aid.',
            ]);
        }
    }

    /**
     * Validate government type.
     *
     * @throws ValidationException
     */
    public function validateGovernmentType(string $requiredGovernment): void
    {
        if ($this->nation->domestic_policy !== $requiredGovernment) {
            throw ValidationException::withMessages([
                'government' => "You must have the $requiredGovernment government type.",
            ]);
        }
    }

    /**
     * Validate nation color.
     *
     * @throws ValidationException
     */
    public function validateColor(array $allowedColors): void
    {
        if (!in_array($this->nation->color, $allowedColors)) {
            throw ValidationException::withMessages([
                'color' => 'Your nation must be one of the following colors: '
                    . implode(', ', $allowedColors),
            ]);
        }
    }

    /**
     * Validate required projects.
     *
     * @throws ValidationException
     */
    public function validateRequiredProjects(array $requiredProjects): void
    {
        if (!empty($requiredProjects)) {
            $nationProjects = PWHelperService::getNationProjects(
                $this->nation->project_bits
            );

            foreach ($requiredProjects as $project) {
                if (!in_array($project, $nationProjects)) {
                    throw ValidationException::withMessages([
                        'projects' => "You must own the $project project to be eligible.",
                    ]);
                }
            }
        }
    }

    /**
     * Validate infrastructure per city.
     *
     * @throws ValidationException
     */
    public function validateInfrastructure(int $minInfraPerCity): void
    {
        // TODO once we get cities, we can calculate the total infra and do this check.
        // for now, we're just gonna basically make this always pass

        // $infPerCity = $totalInfra / $nation->num_cities

        // if ($infPerCity < $minInfraPerCity) {
        //     throw ValidationException::withMessages([
        //         'infrastructure' => "You must have at least $minInfraPerCity infrastructure per city."
        //      ]);
        //  }
    }

}
