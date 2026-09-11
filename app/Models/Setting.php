<?php
// app/Models/Setting.php
//
// Real key-value settings store backing admin-configurable values that
// used to be hardcoded (interest rate, loan multiplier, monthly deduction,
// etc). Cached for 10 minutes per key so every loan/dashboard request
// doesn't hit the DB just to read a config value.
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'label', 'type'];

    public static function get(string $key, $default = null)
    {
        return Cache::remember("setting:{$key}", 600, function () use ($key, $default) {
            $row = self::where('key', $key)->first();
            return $row ? $row->value : $default;
        });
    }

    public static function getFloat(string $key, float $default = 0): float
    {
        return (float) self::get($key, $default);
    }

    public static function getInt(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    public static function set(string $key, $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("setting:{$key}");
    }

    public static function all_settings()
    {
        return self::all()->mapWithKeys(fn ($s) => [$s->key => $s->value]);
    }
}
