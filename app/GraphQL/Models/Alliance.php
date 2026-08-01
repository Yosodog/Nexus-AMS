<?php

namespace App\GraphQL\Models;

use stdClass;

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

    public ?string $flag = null;

    public ?string $forum_link = null;

    public ?string $discord_link = null;

    public ?string $wiki_link = null;

    // public array $bankrecs; // [Bankrec]
    public BankRecords $taxrecs;

    // public array $tax_brackets; // [TaxBracket]
    // public array $wars; // [War!]!
    public ?float $money = null;

    public ?float $coal = null;

    public ?float $oil = null;

    public ?float $uranium = null;

    public ?float $iron = null;

    public ?float $bauxite = null;

    public ?float $lead = null;

    public ?float $gasoline = null;

    public ?float $munitions = null;

    public ?float $steel = null;

    public ?float $aluminum = null;

    public ?float $food = null;

    // public array $awards; // [Award]
    public int $rank;
    // public array $bulletins; // [Bulletin]

    public function buildWithJSON(stdClass $json): void
    {
        $this->id = (string) $json->id;
        $this->name = (string) $json->name;
        $this->acronym = (string) $json->acronym;
        $this->score = (float) $json->score;
        $this->color = (string) $json->color;
        // $this->date = $json->date; // Uncomment and modify based on your DateTime handling
        $this->average_score = (float) ($json->average_score ?? 0); // Avg score can be null lol
        $this->nations = new Nations([]);
        $this->taxrecs = new BankRecords;

        if (isset($json->nations)) {
            foreach ($json->nations as $nation) {
                $nationModel = new Nation;
                $nationModel->buildWithJSON((object) $nation);
                $this->nations->add($nationModel);
            }
        }

        if (isset($json->taxrecs)) {
            foreach ($json->taxrecs as $record) {
                $bankRec = new BankRecord;
                $bankRec->buildWithJSON((object) $record);
                $this->taxrecs->add($bankRec);
            }
        }

        // $this->treaties = $json->treaties; // Uncomment for use
        // $this->alliance_positions = $json->alliance_positions; // Uncomment for use
        $this->accept_members = (bool) $json->accept_members;
        $this->flag = isset($json->flag) ? (string) $json->flag : null;
        $this->forum_link = isset($json->forum_link) ? (string) $json->forum_link : null;
        $this->discord_link = isset($json->discord_link) ? (string) $json->discord_link : null;
        $this->wiki_link = isset($json->wiki_link) ? (string) $json->wiki_link : null;
        $this->money = isset($json->money) ? (float) $json->money : null;
        $this->coal = isset($json->coal) ? (float) $json->coal : null;
        $this->oil = isset($json->oil) ? (float) $json->oil : null;
        $this->uranium = isset($json->uranium) ? (float) $json->uranium : null;
        $this->iron = isset($json->iron) ? (float) $json->iron : null;
        $this->bauxite = isset($json->bauxite) ? (float) $json->bauxite : null;
        $this->lead = isset($json->lead) ? (float) $json->lead : null;
        $this->gasoline = isset($json->gasoline) ? (float) $json->gasoline : null;
        $this->munitions = isset($json->munitions) ? (float) $json->munitions : null;
        $this->steel = isset($json->steel) ? (float) $json->steel : null;
        $this->aluminum = isset($json->aluminum) ? (float) $json->aluminum : null;
        $this->food = isset($json->food) ? (float) $json->food : null;
        $this->rank = (int) $json->rank;
        // $this->awards = $json->awards; // Uncomment for use
        // $this->bulletins = $json->bulletins; // Uncomment for use
    }
}
