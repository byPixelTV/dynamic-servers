<?php

namespace ByPixelTV\Dynamicservers\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use ByPixelTV\Dynamicservers\Models\DynamicTemplateServer;
use Illuminate\Support\Facades\Log;
use Throwable;

class DynamicServerCleanupService
{
    public function checkAll(): void
    {
        DynamicTemplateServer::query()
            ->with('server')
            ->chunkById(50, function ($dynamicServers) {
                foreach ($dynamicServers as $dynamicServer) {
                    $server = $dynamicServer->server;

                    if (!$server) {
                        $dynamicServer->delete();
                        continue;
                    }

                    $this->checkServer($server, $dynamicServer);
                }
            });
    }

    protected function checkServer(
        Server $server,
        DynamicTemplateServer $dynamicServer
    ): void {
        try {
            /** @var DaemonServerRepository $repository */
            $repository = app(DaemonServerRepository::class);

            $details = $repository
                ->setServer($server)
                ->getDetails();

            $state = strtolower((string) ($details['state'] ?? ''));

            Log::info('Dynamic server state', [
                'server_id' => $server->getKey(),
                'state' => $state,
            ]);

            if (!in_array($state, ['offline', 'missing'], true)) {
                return;
            }

            Log::info('Dynamic server is offline, deleting immediately.', [
                'server_id' => $server->getKey(),
                'uuid' => $server->uuid,
                'state' => $state,
            ]);

            $this->deleteServer(
                $server,
                $dynamicServer
            );

        } catch (Throwable $exception) {
            Log::error('Could not check dynamic server state.', [
                'server_id' => $server->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    protected function deleteServer(
        Server $server,
        DynamicTemplateServer $dynamicServer
    ): void {
        try {
            /** @var DaemonServerRepository $repository */
            $repository = app(DaemonServerRepository::class);

            $repository
                ->setServer($server)
                ->delete();

        } catch (Throwable $exception) {
            Log::warning('Could not delete dynamic server from Wings.', [
                'server_id' => $server->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }

        try {
            $dynamicServer->delete();

            $server->delete();

            Log::info('Dynamic server deleted successfully.', [
                'server_id' => $server->getKey(),
            ]);

        } catch (Throwable $exception) {
            Log::error('Could not delete dynamic server from database.', [
                'server_id' => $server->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
