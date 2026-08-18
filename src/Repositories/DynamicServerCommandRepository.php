<?php

namespace ByPixelTV\Dynamicservers\Repositories;

use App\Repositories\Daemon\DaemonServerRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

class DynamicServerCommandRepository extends DaemonServerRepository
{
    /**
     * @throws ConnectionException
     */
    public function command(string $command): Response
    {
        return $this->getHttpClient()->post(
            "/api/servers/{$this->server->uuid}/commands",
            [
                'commands' => [
                    $command,
                ],
            ]
        );
    }
}
