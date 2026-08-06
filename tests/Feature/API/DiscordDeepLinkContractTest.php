<?php

namespace Tests\Feature\API;

use App\Models\Application;
use App\Models\CityGrantRequest;
use App\Models\GrantApplication;
use App\Models\Grants;
use App\Models\Loan;
use App\Models\MemberTransfer;
use App\Models\WarAidRequest;
use App\Services\Discord\DiscordWorkflowLinkService;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

class DiscordDeepLinkContractTest extends TestCase
{
    public function test_workflow_links_are_generated_from_named_routes_with_safe_fallbacks(): void
    {
        $links = app(DiscordWorkflowLinkService::class);
        $grant = $this->existingModel(new Grants, 7);
        $grant->slug = 'discord-grant';
        $grantApplication = $this->existingModel(new GrantApplication, 11);
        $grantApplication->setRelation('grant', $grant);
        $cityGrantRequest = $this->existingModel(new CityGrantRequest, 12);
        $loan = $this->existingModel(new Loan, 13);
        $warAidRequest = $this->existingModel(new WarAidRequest, 14);
        $memberTransfer = $this->existingModel(new MemberTransfer, 15);
        $application = $this->existingModel(new Application, 16);

        $this->assertSame(
            route('grants.show_grants', ['grant' => 'discord-grant'], absolute: false),
            $links->availableGrant($grant),
        );
        $this->assertSame(
            route('grants.show_grants', ['grant' => 'discord-grant'], absolute: false),
            $links->member('grant', $grantApplication),
        );
        $this->assertSame(
            route('grants.city', ['request' => 12], absolute: false),
            $links->member('city_grant', $cityGrantRequest),
        );
        $this->assertSame(
            route('loans.index', ['loan' => 13], absolute: false),
            $links->member('loan', $loan),
        );
        $this->assertSame(
            route('defense.war-aid', ['request' => 14], absolute: false),
            $links->member('war_aid', $warAidRequest),
        );
        $this->assertSame(
            route('accounts', ['member_transfer' => 15], absolute: false),
            $links->member('member_transfer', $memberTransfer),
        );
        $this->assertSame(
            route('admin.loans.view', ['Loan' => 13], absolute: false),
            $links->staff('loan', $loan),
        );
        $this->assertSame(
            route('admin.applications.show', ['application' => 16], absolute: false),
            $links->staff('application', $application),
        );
        $this->assertSame(route('user.dashboard', absolute: false), $links->member('grant'));
        $this->assertSame(route('admin.loans', absolute: false), $links->staff('loan'));
        $this->assertSame(route('admin.applications.index', absolute: false), $links->staff('application'));
    }

    public function test_representative_discord_links_resolve_and_preserve_the_guest_destination(): void
    {
        $links = app(DiscordWorkflowLinkService::class);
        $grant = $this->existingModel(new Grants, 21);
        $grant->slug = 'browser-smoke-grant';
        $warAidRequest = $this->existingModel(new WarAidRequest, 22);
        $memberTransfer = $this->existingModel(new MemberTransfer, 23);
        $application = $this->existingModel(new Application, 24);
        $paths = [
            $links->availableGrant($grant),
            $links->member('war_aid', $warAidRequest),
            $links->member('member_transfer', $memberTransfer),
            $links->staff('application', $application),
        ];

        foreach ($paths as $path) {
            session()->forget('url.intended');

            $this->get($path)->assertRedirectToRoute('login');

            $this->assertSame(url($path), session('url.intended'));
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @return TModel
     */
    private function existingModel(Model $model, int $id): Model
    {
        $model->setAttribute($model->getKeyName(), $id);
        $model->exists = true;

        return $model;
    }
}
