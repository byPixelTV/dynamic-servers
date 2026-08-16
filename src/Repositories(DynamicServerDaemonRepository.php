<?php

namespace ByPixelTV\Dynamicservers\Repositories;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use RuntimeException;
use Throwable;

class DynamicServerDaemonRepository extends DaemonServerRepository
{
    public function power(string $action): void
    {
        if (!$this->server instanceof Server) {
            throw new RuntimeException(
                'No server has been set on the daemon repository.'
            );
        }

        if (!in_array(
            $action,
            ['start', 'stop', 'restart', 'kill'],
            true
        )) {
            throw new RuntimeException(
                "Unsupported power action: {$action}"
            );
        }

        $wingsAction = $action === 'kill'
            ? 'terminate'
            : $action;

        try {
            $this->getHttpClient()->post(
                "/api/servers/{$this->server->uuid}/power",
                [
                    'json' => [
                        'action' => $wingsAction,
                    ],
                ]
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                "Could not send {$action} power action to Wings: "
                . $exception->getMessage(),
                previous: $exception
            );
        }
    }
}
