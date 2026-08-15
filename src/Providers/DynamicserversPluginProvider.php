<?php

namespace ByPixelTV\Dynamicservers\Providers;

use App\Models\Server;
use ByPixelTV\Dynamicservers\Jobs\AutoScaleDynamicTemplate;
use ByPixelTV\Dynamicservers\Models\DynamicTemplateServer;
use Illuminate\Support\ServiceProvider;

class DynamicserversPluginProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        config()->set(
            'livewire.temporary_file_upload.rules',
            'file|max:104857600'
        );
        
        Server::deleted(function (Server $server): void {
            $dynamicServer = DynamicTemplateServer::query()
                ->where('server_id', $server->getKey())
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
            )->delay(now()->addSecond());
        });
    }
}
