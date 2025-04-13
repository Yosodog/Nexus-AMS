<?php

namespace App\Console\Commands;

use App\Models\Treaty;
use App\GraphQL\Models\Treaty as TreatyGraphQL;
use App\Services\GraphQLQueryBuilder;
use App\Services\QueryService;
use Illuminate\Console\Command;

class SyncTreaties extends Command
{
    protected $signature = 'sync:treaties';
    protected $description = 'Syncs approved treaties from the Politics & War API';

    public function handle(): void
    {
        $query = (new GraphQLQueryBuilder())
            ->setRootField('treaties')
            ->addArgument('first', 1000)
            ->addNestedField('data', fn(GraphQLQueryBuilder $builder) => $builder->addFields([
                'id',
                'date',
                'treaty_type',
                'turns_left',
                'alliance1_id',
                'alliance2_id',
                'approved',
            ]));

        $response = (new QueryService())->sendQuery($query);

        $treatyIDs = [];

        foreach ($response as $treatyJSON) {
            $graphQLTreaty = new TreatyGraphQL();
            $graphQLTreaty->buildWithJSON((object)$treatyJSON);

            if (! $graphQLTreaty->approved) {
                continue;
            }

            Treaty::updateOrInsert(
                ['pw_id' => $graphQLTreaty->id],
                [
                    'pw_date' => $graphQLTreaty->date,
                    'turns_left' => $graphQLTreaty->turns_left,
                    'alliance1_id' => $graphQLTreaty->alliance1_id,
                    'alliance2_id' => $graphQLTreaty->alliance2_id,
                    'type' => $graphQLTreaty->treaty_type,
                ]
            );

            $treatyIDs[] = $graphQLTreaty->id;
        }

        Treaty::whereNotIn('pw_id', $treatyIDs)->delete();
    }
}