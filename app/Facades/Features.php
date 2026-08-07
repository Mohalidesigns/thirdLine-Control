<?php

namespace App\Facades;

use App\Services\FeatureService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static bool enabled(string $key, ?\App\Models\User $user = null)
 * @method static array enabledKeys(?\App\Models\User $user = null)
 * @method static void flush()
 *
 * @see FeatureService
 */
class Features extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FeatureService::class;
    }
}
