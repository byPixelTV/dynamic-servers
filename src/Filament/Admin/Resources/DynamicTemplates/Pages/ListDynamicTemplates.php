<?php
namespace ByPixelTV\Dynamicservers\Filament\Admin\Resources\DynamicTemplates\Pages;

use ByPixelTV\Dynamicservers\Filament\Admin\Resources\DynamicTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDynamicTemplates extends ListRecords
{
    protected static string $resource = DynamicTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
