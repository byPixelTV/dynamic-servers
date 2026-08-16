<?php

namespace ByPixelTV\Dynamicservers\Filament\Server\Widgets;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use ByPixelTV\Dynamicservers\Models\DynamicTemplateServer;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Throwable;

class DynamicServerPowerWatcher extends Widget
{
    protected string $view =
        'dynamicservers::filament.server.widgets.dynamic-server-power-watcher';

    #[On('dynamicserver-power-action')]
    public function recordPowerAction(
        string $state,
        string $uuid
    ): void {
        if (!in_array(
            $state,
            ['stop', 'kill'],
            true
        )) {
            return;
        }

        /** @var Server|null $server */
        $server = Filament::getTenant();

        if (!$server) {
            return;
        }

        if ($server->uuid !== $uuid) {
            return;
        }

        /** @var DynamicTemplateServer|null $dynamicServer */
        $dynamicServer = DynamicTemplateServer::query()
            ->where(
                'server_id',
                $server->getKey()
            )
            ->first();

        if (!$dynamicServer) {
            return;
        }

        $this->deleteDynamicServer(
            $server
        );
    }

    private function deleteDynamicServer(
        Server $server
    ): void {
        $serverId = $server->getKey();

        Log::info(
            'Dynamic server stop/kill requested. Deleting immediately.',
            [
                'server_id' => $serverId,
            ]
        );

        try {
            $repository = app(
                DaemonServerRepository::class
            );

            $repository
                ->setServer($server)
                ->delete();
        } catch (Throwable $exception) {
            Log::warning(
                'Could not immediately delete dynamic server from Wings.',
                [
                    'server_id' => $serverId,
                    'error' => $exception->getMessage(),
                ]
            );
        }

        try {
            $server->delete();

            Log::info(
                'Dynamic server immediately deleted.',
                [
                    'server_id' => $serverId,
                ]
            );
        } catch (Throwable $exception) {
            Log::error(
                'Could not immediately delete dynamic server from database.',
                [
                    'server_id' => $serverId,
                    'error' => $exception->getMessage(),
                ]
            );
        }
    }
}
