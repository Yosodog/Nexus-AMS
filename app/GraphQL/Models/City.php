<?php

namespace App\GraphQL\Models;

class City {
    public string $id;
    public string $nation_id;
    // public Nation $nation; // Nation
    public string $name;
    // public Date $date; // Date
    public float $infrastructure;
    public float $land;
    public bool $powered;
    public int $oil_power;
    public int $wind_power;
    public int $coal_power;
    public int $nuclear_power;
    public int $coal_mine;
    public int $oil_well;
    public int $uranium_mine;
    public int $barracks;
    public int $farm;
    public int $police_station;
    public int $hospital;
    public int $recycling_center;
    public int $subway;
    public int $supermarket;
    public int $bank;
    public int $shopping_mall;
    public int $stadium;
    public int $lead_mine;
    public int $iron_mine;
    public int $bauxite_mine;
    public int $oil_refinery;
    public int $aluminum_refinery;
    public int $steel_mill;
    public int $munitions_factory;
    public int $factory;
    public int $hangar;
    public int $drydock;
    // public Date $nuke_date; // Date

    /**
     * @param \stdClass $json
     * @return void
     */
    public function buildWithJSON(\stdClass $json): void {
        $this->id = $json->id;
        $this->nation_id = $json->nation_id;
        // $this->nation = $json->nation; // Uncomment for use
        $this->name = $json->name;
        // $this->date = $json->date; // Uncomment and modify based on your Date handling
        $this->infrastructure = $json->infrastructure;
        $this->land = $json->land;
        $this->powered = $json->powered;
        $this->oil_power = $json->oil_power;
        $this->wind_power = $json->wind_power;
        $this->coal_power = $json->coal_power;
        $this->nuclear_power = $json->nuclear_power;
        $this->coal_mine = $json->coal_mine;
        $this->oil_well = $json->oil_well;
        $this->uranium_mine = $json->uranium_mine;
        $this->barracks = $json->barracks;
        $this->farm = $json->farm;
        $this->police_station = $json->police_station;
        $this->hospital = $json->hospital;
        $this->recycling_center = $json->recycling_center;
        $this->subway = $json->subway;
        $this->supermarket = $json->supermarket;
        $this->bank = $json->bank;
        $this->shopping_mall = $json->shopping_mall;
        $this->stadium = $json->stadium;
        $this->lead_mine = $json->lead_mine;
        $this->iron_mine = $json->iron_mine;
        $this->bauxite_mine = $json->bauxite_mine;
        $this->oil_refinery = $json->oil_refinery;
        $this->aluminum_refinery = $json->aluminum_refinery;
        $this->steel_mill = $json->steel_mill;
        $this->munitions_factory = $json->munitions_factory;
        $this->factory = $json->factory;
        $this->hangar = $json->hangar;
        $this->drydock = $json->drydock;
        // $this->nuke_date = $json->nuke_date; // Uncomment and modify based on your Date handling
    }
}

