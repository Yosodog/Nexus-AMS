<?php

namespace Tests\Unit\Nel;

use App\Models\Nation;
use App\Models\NationMilitary;
use App\Nel\NelEngine;
use App\Nel\NelEvaluator;
use App\Nel\NelParser;
use App\Nel\NelTokenizer;
use App\Nel\Profiles\NationNelProfile;
use PHPUnit\Framework\TestCase;

class NelIntegrationTest extends TestCase
{
    public function test_engine_runs_against_profiled_nation(): void
    {
        $nation = new Nation;
        $nation->forceFill([
            'id' => 1,
            'nation_name' => 'Arcadia',
            'score' => 1250.5,
        ]);

        $military = new NationMilitary;
        $military->forceFill([
            'soldiers' => 15000,
            'tanks' => 1200,
            'aircraft' => 50,
            'ships' => 10,
            'spies' => 55,
        ]);

        $nation->setRelation('military', $military);

        $profile = new NationNelProfile;
        $variables = $profile->buildVariables($nation);

        $engine = new NelEngine(new NelParser(new NelTokenizer), new NelEvaluator);

        $expression = 'nation.score > 500 && nation.military.soldiers > 10000';
        $result = $engine->evaluate($expression, $variables);

        $this->assertTrue($result);
    }
}
