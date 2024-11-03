<?php

namespace App\GraphQL\Models;

class Alliance
{
    public string $id;
    public string $name;
    public string $acronym;
    public float $score;
    public string $color;
    // public DateTimeAuto $date;
    public Nations $nations;
    public float $average_score;
    // public array $treaties; // [Treaty!]!
    // public array $alliance_positions; // [AlliancePosition!]!
    public bool $accept_members;
    public string $flag;
    public string $forum_link;
    public string $discord_link;
    public string $wiki_link;
    // public array $bankrecs; // [Bankrec]
    // public array $taxrecs; // [Bankrec]
    // public array $tax_brackets; // [TaxBracket]
    // public array $wars; // [War!]!
    public float|null $money;
    public float|null $coal;
    public float|null $oil;
    public float|null $uranium;
    public float|null $iron;
    public float|null $bauxite;
    public float|null $lead;
    public float|null $gasoline;
    public float|null $munitions;
    public float|null $steel;
    public float|null $aluminum;
    public float|null $food;
    // public array $awards; // [Award]
    public int $rank;
    // public array $bulletins; // [Bulletin]

    /**
     * @param \stdClass $json
     * @return void
     */
    public function buildWithJSON(\stdClass $json): void {
        $this->id = $json->id;
        $this->name = $json->name;
        $this->acronym = $json->acronym;
        $this->score = $json->score;
        $this->color = $json->color;
        // $this->date = $json->date; // Uncomment and modify based on your DateTime handling
        $this->average_score = $json->average_score;
        if (isset($json->nations))
        {
            $this->nations = new Nations([]);
            foreach ($json->nations as $nation)
            {
                $nationModel = new Nation();
                $nationModel->buildWithJSON((object)$nation);
                $this->nations->add($nationModel);
            }
        }

        // $this->treaties = $json->treaties; // Uncomment for use
        // $this->alliance_positions = $json->alliance_positions; // Uncomment for use
        $this->accept_members = $json->accept_members;
        $this->flag = $json->flag;
        $this->forum_link = $json->forum_link;
        $this->discord_link = $json->discord_link;
        $this->wiki_link = $json->wiki_link;
        $this->money = $json->money;
        $this->coal = $json->coal;
        $this->oil = $json->oil;
        $this->uranium = $json->uranium;
        $this->iron = $json->iron;
        $this->bauxite = $json->bauxite;
        $this->lead = $json->lead;
        $this->gasoline = $json->gasoline;
        $this->munitions = $json->munitions;
        $this->steel = $json->steel;
        $this->aluminum = $json->aluminum;
        $this->food = $json->food;
        $this->rank = $json->rank;
        // $this->awards = $json->awards; // Uncomment for use
        // $this->bulletins = $json->bulletins; // Uncomment for use
    }
}
