<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Telescope::night();

        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
            return $isLocal ||
                $entry->isReportableException() ||
                $entry->isFailedRequest() ||
                $entry->isFailedJob() ||
                $entry->isScheduledTask() ||
                $entry->hasMonitoredTag() ||
                $entry->isSlowQuery();
        });

        Telescope::avatar(function (?string $id, ?string $email) {
            $fallbackAvatar = sprintf(
                'https://www.gravatar.com/avatar/%s?d=mp',
                md5(strtolower(trim((string) $email)))
            );

            if (is_null($id)) {
                return $fallbackAvatar;
            }

            return User::find($id)?->nation?->flag ?? $fallbackAvatar;
        });
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        Telescope::hideRequestParameters([
            'ams_shared_token',
            'bot_token',
            'bootstrap_token',
            'configuration',
            'core_token',
            'guild_id',
            'hmac_secret',
            'password',
            'password_confirmation',
            'politics_war_api_token',
            'relay_private_key',
            'redis_url',
        ]);

        if ($this->app->environment('local')) {
            return;
        }

        Telescope::hideRequestParameters([
            '_token',
            'apiKey',
            'api_key',
            'code',
            'current_password',
            'key',
            'mutationKey',
            'mutation_key',
            'recovery_code',
            'token',
            'two_factor_code',
            'verification_code',
        ]);

        Telescope::hideRequestHeaders([
            'authorization',
            'cookie',
            'php-auth-pw',
            'x-api-key',
            'x-bot-key',
            'x-csrf-token',
            'x-discord-signature',
            'x-discord-timestamp',
            'x-nexus-bootstrap-token',
            'x-nexus-api-key',
            'x-xsrf-token',
        ]);
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', function (User $user) {
            return Gate::allows('view-diagnostic-info');
        });
    }
}
