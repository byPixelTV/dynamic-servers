<?php

namespace ByPixelTV\Dynamicservers;

use App\Models\Role;
use ByPixelTV\Dynamicservers\Filament\Admin\Resources\DynamicTemplateResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

class DynamicserversPlugin implements Plugin
{
    public function getId(): string
    {
        return 'dynamicservers';
    }

    public function register(Panel $panel): void
    {

        if ($panel->getId() === 'admin') {
            $panel->resources([
                DynamicTemplateResource::class,
            ]);
        }

        Role::registerCustomDefaultPermissions('dynamic_template');
        Role::registerCustomModelIcon(
            'dynamic_template',
            'tabler-server-2'
        );
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
