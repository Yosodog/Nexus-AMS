<?php

namespace App\Console\Commands;

use App\Services\InitialAdministratorProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ProvisionNexusAdministrator extends Command
{
    protected $signature = 'nexus:provision-admin';

    protected $description = 'Provision the initial Nexus administrator from a bounded JSON document on stdin';

    public function __construct(private readonly InitialAdministratorProvisioner $provisioner)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $input = $this->readInput();
            $validated = Validator::make($input, [
                'email' => ['required', 'email:rfc', 'max:255'],
                'password' => ['required', 'string', 'min:12', 'max:1024'],
                'nation_id' => ['required', 'integer', 'min:1'],
            ])->validate();

            $created = $this->provisioner->provision([
                'email' => $validated['email'],
                'password' => $validated['password'],
                'nation_id' => (int) $validated['nation_id'],
            ]);

            $this->line(json_encode(['status' => $created ? 'created' : 'already_exists'], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Initial administrator provisioning failed: '.$this->safeMessage($exception));

            return self::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    private function readInput(): array
    {
        $contents = stream_get_contents(STDIN, 16 * 1024 + 1);

        if (! is_string($contents) || $contents === '' || strlen($contents) > 16 * 1024) {
            throw new RuntimeException('The provisioning input is missing or too large.');
        }

        $decoded = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || array_diff(array_keys($decoded), ['email', 'password', 'nation_id']) !== []) {
            throw new RuntimeException('The provisioning input contains unsupported fields.');
        }

        return $decoded;
    }

    private function safeMessage(Throwable $exception): string
    {
        return $exception instanceof RuntimeException
            ? $exception->getMessage()
            : ($exception instanceof ValidationException
                ? 'The provisioning input is invalid.'
                : 'The database could not complete the provisioning request.');
    }
}
