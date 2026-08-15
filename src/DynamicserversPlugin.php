<?php

namespace ByPixelTV\Dynamicservers;

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
        // Allows you to use any configuration option that is available to the panel.
        // This includes registering resources, custom pages, themes, render hooks and more.
    }

    public function boot(Panel $panel): void
    {
        // Is run only when the panel that the plugin is being registered to is actually in-use. It is executed by a middleware class.
    }
}
