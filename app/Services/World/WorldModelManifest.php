<?php

namespace App\Services\World;

use App\Models\Alliance;
use App\Models\City;
use App\Models\MarketPriceSnapshot;
use App\Models\MarketPriceSnapshotItem;
use App\Models\MarketTrade;
use App\Models\Nation;
use App\Models\RadiationSnapshot;
use App\Models\TradePrice;
use App\Models\Treaty;
use App\Models\War;
use App\Models\WarAttack;
use Illuminate\Database\Eloquent\Model;

final class WorldModelManifest
{
    public const CONTRACT_VERSION = 1;

    /**
     * @var array<string, class-string<Model>>
     */
    private const MODELS_BY_TABLE = [
        'alliances' => Alliance::class,
        'nations' => Nation::class,
        'cities' => City::class,
        'wars' => War::class,
        'war_attacks' => WarAttack::class,
        'treaties' => Treaty::class,
        'trade_prices' => TradePrice::class,
        'market_trades' => MarketTrade::class,
        'market_price_snapshots' => MarketPriceSnapshot::class,
        'market_price_snapshot_items' => MarketPriceSnapshotItem::class,
        'radiation_snapshots' => RadiationSnapshot::class,
    ];

    /**
     * @return array<string, class-string<Model>>
     */
    public static function modelsByTable(): array
    {
        return self::MODELS_BY_TABLE;
    }

    /**
     * @return list<class-string<Model>>
     */
    public static function models(): array
    {
        return array_values(self::MODELS_BY_TABLE);
    }

    /**
     * @param  Model|class-string<Model>  $model
     */
    public static function contains(Model|string $model): bool
    {
        $modelClass = $model instanceof Model ? $model::class : $model;

        return in_array($modelClass, self::MODELS_BY_TABLE, true);
    }
}
