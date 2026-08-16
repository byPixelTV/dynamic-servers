<?php

namespace ByPixelTV\Dynamicservers\Providers;

use App\Enums\ConsoleWidgetPosition;
use App\Filament\Server\Pages\Console;
use App\Models\Role;
use App\Models\Server;
use ByPixelTV\Dynamicservers\Filament\Server\Widgets\DynamicServerPowerWatcher;
use ByPixelTV\Dynamicservers\Jobs\AutoScaleDynamicTemplate;
use ByPixelTV\Dynamicservers\Models\DynamicTemplate;
use ByPixelTV\Dynamicservers\Models\DynamicTemplateServer;
use ByPixelTV\Dynamicservers\Policies\DynamicTemplatePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class DynamicserversPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        Console::registerCustomWidgets(
            ConsoleWidgetPosition::Bottom,
            [
                DynamicServerPowerWatcher::class,
            ]
        );

        Role::registerCustomPermissions([
            'dynamicTemplate' => [
                'viewAny',
                'view',
                'create',
                'update',
                'delete',
                'createServer',
            ],
        ]);

        Role::registerCustomModelIcon(
            'dynamicTemplate',
            'tabler-server-2'
        );
    }

    public function boot(): void
    {
        Gate::policy(
            DynamicTemplate::class,
            DynamicTemplatePolicy::class
        );

        config()->set(
            'livewire.temporary_file_upload.rules',
            'file|max:104857600'
        );

        Server::deleting(function (Server $server): void {
            if (!Schema::hasTable('dynamic_template_servers')) {
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

            $templateId = (int) $dynamicServer->getAttribute(
                'dynamic_template_id'
            );

            $dynamicServer->delete();

            AutoScaleDynamicTemplate::dispatch(
                $templateId
            )->delay(
                now()->addSecond()
            );
        });
    }
}
