<?php

namespace ByPixelTV\Dynamicservers\Filament\Admin\Resources;

use App\Models\Allocation;
use BackedEnum;
use ByPixelTV\Dynamicservers\Filament\Admin\Resources\DynamicTemplates\Pages\CreateDynamicTemplate;
use ByPixelTV\Dynamicservers\Filament\Admin\Resources\DynamicTemplates\Pages\EditDynamicTemplate;
use ByPixelTV\Dynamicservers\Filament\Admin\Resources\DynamicTemplates\Pages\ListDynamicTemplates;
use ByPixelTV\Dynamicservers\Models\DynamicTemplate;
use ByPixelTV\Dynamicservers\Services\DynamicServerCreationService;
use Exception;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class DynamicTemplateResource extends Resource
{
    protected static ?string $model = DynamicTemplate::class;
    protected static string|null|BackedEnum $navigationIcon = 'tabler-server-2';
    protected static ?string $navigationLabel = 'Dynamic Templates';

    public static function getFormSections(): array
    {
        return [
            Section::make('General')
                ->columns()
                ->schema([
                    Forms\Components\TextInput::make('name')->required()->columnSpanFull(),
                    Forms\Components\Select::make('egg_id')->relationship('egg', 'name')->required()->searchable()->preload(),
                    Forms\Components\Select::make('node_id')->relationship('node', 'name')->required()->searchable()->preload(),
                ]),
            Section::make('Resources')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('memory')->numeric()->required()->suffix('MB'),
                    Forms\Components\TextInput::make('disk')->numeric()->required()->suffix('MB'),
                    Forms\Components\TextInput::make('cpu')->numeric()->suffix('%'),
                ]),
            Section::make('Scaling')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('port_range_start')->numeric()->required(),
                    Forms\Components\TextInput::make('port_range_end')->numeric()->required(),
                    Forms\Components\TextInput::make('min_servers')->numeric()->default(0)->required(),
                    Forms\Components\Toggle::make('auto_creation')->inline(false)->columnSpanFull(),
                ]),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(static::getFormSections());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('egg.name'),
                Tables\Columns\TextColumn::make('node.name'),
                Tables\Columns\TextColumn::make('min_servers'),
                Tables\Columns\IconColumn::make('auto_creation')->boolean(),
            ])
            ->recordActions([
                Action::make('create_server')
                    ->label('Create Server')
                    ->icon('tabler-plus')
                    ->visible(
                        fn (): bool => user()?->can('createServer dynamicTemplate') ?? false
                    )
                    ->color('success')
                    ->schema([
                        Forms\Components\TextInput::make('amount')
                            ->label('Amount')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                    ])
                    ->action(function (
                        array $data,
                        DynamicTemplate $record,
                        DynamicServerCreationService $creationService
                    ) {
                        abort_unless(
                            user()?->can('createServer dynamicTemplate'),
                            403
                        );
                        
                        $amount = (int) $data['amount'];

                        $freeAllocations = Allocation::query()
                            ->where('node_id', $record->node_id)
                            ->whereNull('server_id')
                            ->whereBetween('port', [
                                $record->port_range_start,
                                $record->port_range_end,
                            ])
                            ->count();

                        if ($freeAllocations < $amount) {
                            Notification::make()
                                ->title('Not enough free allocations')
                                ->body(
                                    "Requested: $amount. Available: $freeAllocations."
                                )
                                ->danger()
                                ->send();

                            return;
                        }

                        $created = 0;

                        for ($i = 0; $i < $amount; $i++) {
                            try {
                                $creationService->create($record);

                                $created++;
                            } catch (Exception $exception) {
                                Notification::make()
                                    ->title('Server creation failed')
                                    ->body($exception->getMessage())
                                    ->danger()
                                    ->send();

                                return;
                            }
                        }

                        Notification::make()
                            ->title('Servers created')
                            ->body("$created server(s) created.")
                            ->success()
                            ->send();
                    })
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDynamicTemplates::route('/'),
            'create' => CreateDynamicTemplate::route('/create'),
            'edit' => EditDynamicTemplate::route('/{record}/edit'),
        ];
    }
}
