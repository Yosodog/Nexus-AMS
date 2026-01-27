<?php

namespace App\Http\Requests;

use App\Enums\WarTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RunWarSimulationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'iterations' => ['required', 'integer', 'min:100', 'max:20000'],
            'seed' => ['nullable', 'integer'],
            'nation_attacker' => ['required', 'array'],
            'nation_attacker.nation_id' => ['nullable', 'integer'],
            'nation_attacker.soldiers' => ['required', 'integer', 'min:0'],
            'nation_attacker.tanks' => ['required', 'integer', 'min:0'],
            'nation_attacker.aircraft' => ['required', 'integer', 'min:0'],
            'nation_attacker.ships' => ['required', 'integer', 'min:0'],
            'nation_attacker.war_policy' => ['required', 'string', 'max:50'],
            'nation_attacker.is_fortified' => ['required', 'boolean'],
            'nation_attacker.money' => ['nullable', 'numeric'],
            'nation_attacker.cities' => ['required', 'integer', 'min:0'],
            'nation_attacker.highest_city_infra' => ['required', 'numeric', 'min:0'],
            'nation_attacker.highest_city_population' => ['required', 'integer', 'min:0'],
            'nation_attacker.avg_infra' => ['nullable', 'numeric', 'min:0'],
            'nation_defender' => ['required', 'array'],
            'nation_defender.nation_id' => ['nullable', 'integer'],
            'nation_defender.soldiers' => ['required', 'integer', 'min:0'],
            'nation_defender.tanks' => ['required', 'integer', 'min:0'],
            'nation_defender.aircraft' => ['required', 'integer', 'min:0'],
            'nation_defender.ships' => ['required', 'integer', 'min:0'],
            'nation_defender.war_policy' => ['required', 'string', 'max:50'],
            'nation_defender.is_fortified' => ['required', 'boolean'],
            'nation_defender.money' => ['nullable', 'numeric'],
            'nation_defender.cities' => ['required', 'integer', 'min:0'],
            'nation_defender.highest_city_infra' => ['required', 'numeric', 'min:0'],
            'nation_defender.highest_city_population' => ['required', 'integer', 'min:0'],
            'nation_defender.avg_infra' => ['nullable', 'numeric', 'min:0'],
            'context' => ['required', 'array'],
            'context.war_type' => ['required', Rule::in(WarTypeEnum::values())],
            'context.attacker_policy' => ['required', 'string', 'max:50'],
            'context.defender_policy' => ['required', 'string', 'max:50'],
            'context.air_superiority_owner' => ['required', Rule::in(['attacker', 'defender', 'none'])],
            'context.ground_control_owner' => ['required', Rule::in(['attacker', 'defender', 'none'])],
            'context.blockade_owner' => ['required', Rule::in(['attacker', 'defender', 'none'])],
            'context.blitz_active_attacker' => ['required', 'boolean'],
            'context.blitz_active_defender' => ['required', 'boolean'],
            'action' => ['required', 'array'],
            'action.type' => ['required', Rule::in(['ground', 'air', 'naval'])],
            'action.attacking_soldiers' => ['required_if:action.type,ground', 'integer', 'min:0'],
            'action.attacking_tanks' => ['required_if:action.type,ground', 'integer', 'min:0'],
            'action.arm_soldiers_with_munitions' => ['required_if:action.type,ground', 'boolean'],
            'action.attacking_aircraft' => ['required_if:action.type,air', 'integer', 'min:0'],
            'action.target' => ['required_if:action.type,air', Rule::in(['infra', 'aircraft', 'soldiers', 'tanks', 'ships', 'money'])],
            'action.attacking_ships' => ['required_if:action.type,naval', 'integer', 'min:0'],
        ];
    }

}
