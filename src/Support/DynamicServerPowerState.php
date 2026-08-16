<?php

namespace ByPixelTV\Dynamicservers\Support;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;

class DynamicServerPowerState
{
    public static function set(
        Server $server,
        string $action
    ): void {
        if (!in_array(
            $action,
            ['start', 'restart', 'stop', 'kill'],
            true
        )) {
            return;
        }

        Cache::put(
            self::key($server),
            $action,
            now()->addMinutes(2)
        );
    }

    public static function get(
        Server $server
    ): ?string {
        $value = Cache::get(
            self::key($server)
        );

        return is_string($value)
            ? $value
            : null;
    }

    public static function clear(
        Server $server
    ): void {
        Cache::forget(
            self::key($server)
        );
    }

    private static function key(
        Server $server
    ): string {
        return "dynamicservers:power-action:{$server->getKey()}";
    }
}
