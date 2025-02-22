<?php

namespace App\Actions\Fortify;

use App\Models\Nations;
use App\Models\User;
use App\Notifications\NationVerification;
use App\Rules\InAllianceAndMember;
use App\Services\NationQueryService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param array<string, string> $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
            'nation_id' => [
                'required',
                'integer',
                Rule::unique(User::class),
                new InAllianceAndMember
            ]
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
            'nation_id' => $input["nation_id"]
        ]);

        $user->notify(new NationVerification($user));

        return $user;
    }
}
