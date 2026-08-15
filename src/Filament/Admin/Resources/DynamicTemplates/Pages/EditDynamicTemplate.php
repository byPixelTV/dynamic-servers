<?php

namespace ByPixelTV\Dynamicservers\Filament\Admin\Resources\DynamicTemplates\Pages;

use App\Models\Allocation;
use App\Models\Egg;
use ByPixelTV\Dynamicservers\Filament\Admin\Resources\DynamicTemplateResource;
use ByPixelTV\Dynamicservers\Jobs\AutoScaleDynamicTemplate;
use ByPixelTV\Dynamicservers\Livewire\TemplateFileManager;
use ByPixelTV\Dynamicservers\Models\DynamicTemplate;
use Closure;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\StateCasts\BooleanStateCast;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class EditDynamicTemplate extends EditRecord
{
    protected static string $resource = DynamicTemplateResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (empty($data['startup']) && !empty($data['egg_id'])) {
            $egg = Egg::query()->find($data['egg_id']);

            if ($egg) {
                $data['startup'] = collect($egg->startup_commands ?? [])->first();
            }
        }

        return $data;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Tabs')
                ->persistTabInQueryString()
                ->columnSpanFull()
                ->tabs([
                    Tab::make('General')
                        ->icon('tabler-info-circle')
                        ->columns()
                        ->schema([
                            Forms\Components\TextInput::make('name')
                                ->required()
                                ->columnSpanFull(),
                            Forms\Components\Select::make('owner_id')
                                ->label('Server Owner')
                                ->relationship('owner', 'username')
                                ->searchable()
                                ->preload()
                                ->required(),
                            Forms\Components\Select::make('egg_id')
                                ->relationship('egg', 'name')
                                ->searchable()
                                ->preload()
                                ->live()
                                ->afterStateUpdated(function ($state, Set $set) {
                                    $egg = Egg::query()->find($state);

                                    if (!$egg) {
                                        return;
                                    }

                                    $set('image', collect($egg->docker_images ?? [])->first());
                                    $set('startup', collect($egg->startup_commands ?? [])->first());
                                })
                                ->required(),
                            Forms\Components\Select::make('select_image')
                                ->label('Docker Image')
                                ->live()
                                ->afterStateUpdated(fn (Set $set, $state) => $set('image', $state))
                                ->options(function (Get $get) {
                                    $egg = Egg::query()->find($get('egg_id'));
                                    $images = $egg->docker_images ?? [];

                                    return array_flip($images) + ['ghcr.io/custom-image' => 'Custom Image'];
                                })
                                ->formatStateUsing(function (Get $get) {
                                    $egg = Egg::query()->find($get('egg_id'));
                                    $images = $egg->docker_images ?? [];
                                    $current = $get('image');

                                    return in_array($current, $images) ? $current : 'ghcr.io/custom-image';
                                })
                                ->selectablePlaceholder(false)
                                ->dehydrated(false),

                            Forms\Components\TextInput::make('image')
                                ->label('Image')
                                ->required()
                                ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                    $egg = Egg::query()->find($get('egg_id'));
                                    $images = $egg->docker_images ?? [];

                                    $set('select_image', in_array($state, $images) ? $state : 'ghcr.io/custom-image');
                                })
                                ->live(onBlur: true)
                                ->columnSpanFull(),
                            Forms\Components\Textarea::make('startup')
                                ->label('Startup Command')
                                ->placeholder('java -Xms128M -Xmx{{SERVER_MEMORY}}M -jar {{SERVER_JARFILE}}')
                                ->helperText('Leave empty to use the default startup command from the selected egg.')
                                ->rows(3)
                                ->columnSpanFull(),
                            Forms\Components\Select::make('node_id')
                                ->relationship('node', 'name')
                                ->searchable()
                                ->preload()
                                ->live()
                                ->required(),
                        ]),

                    Tab::make('Resources')
                        ->icon('tabler-server-2')
                        ->schema([
                            Grid::make(4)
                                ->columnSpanFull()
                                ->schema([
                                    Forms\Components\TextInput::make('memory')
                                        ->label('Memory')->inlineLabel()
                                        ->numeric()->required()
                                        ->minValue(128)
                                        ->suffix('MB')
                                        ->helperText('Unlimited memory is not supported by the panel, so a minimum of 128MB is required.')
                                        ->columnSpan(2),
                                ]),

                            Grid::make(4)
                                ->columnSpanFull()
                                ->schema([
                                    Forms\Components\ToggleButtons::make('unlimited_cpu')
                                        ->label('CPU')->inlineLabel()->inline()
                                        ->live()
                                        ->dehydrated(false)
                                        ->formatStateUsing(fn (Get $get) => (int) $get('cpu') === 0)
                                        ->stateCast(new BooleanStateCast(false, true))
                                        ->afterStateUpdated(fn (Set $set) => $set('cpu', 0))
                                        ->options([1 => 'Unlimited', 0 => 'Limited'])
                                        ->colors([1 => 'primary', 0 => 'warning'])
                                        ->columnSpan(2),

                                    Forms\Components\TextInput::make('cpu')
                                        ->label('CPU Limit')->inlineLabel()
                                        ->hidden(fn (Get $get) => $get('unlimited_cpu'))
                                        ->dehydratedWhenHidden()
                                        ->numeric()->suffix('%')
                                        ->columnSpan(2),
                                ]),

                            Grid::make(4)
                                ->columnSpanFull()
                                ->schema([
                                    Forms\Components\TextInput::make('disk')
                                        ->label('Disk')->inlineLabel()
                                        ->numeric()->required()->suffix('MB')
                                        ->columnSpan(2),
                                ]),
                        ]),

                    Tab::make('Scaling')
                        ->icon('tabler-adjustments')
                        ->columns(3)
                        ->schema([
                            Forms\Components\TextInput::make('port_range_start')
                                ->numeric()
                                ->required()
                                ->live(onBlur: true),

                            Forms\Components\TextInput::make('port_range_end')
                                ->numeric()
                                ->required()
                                ->live(onBlur: true)
                                ->rules([
                                    fn (Get $get) => function (
                                        string $attribute,
                                               $value,
                                        Closure $fail
                                    ) use ($get) {
                                        $nodeId = $get('node_id');
                                        $start = (int) $get('port_range_start');
                                        $end = (int) $value;

                                        if (!$nodeId || !$start || !$end) {
                                            return;
                                        }

                                        if ($start > $end) {
                                            $fail('The end port must be greater than or equal to the start port.');

                                            return;
                                        }

                                        $exists = Allocation::query()
                                            ->where('node_id', $nodeId)
                                            ->whereBetween('port', [$start, $end])
                                            ->exists();

                                        if (!$exists) {
                                            $fail('No allocations exist in this port range on the selected node.');
                                        }
                                    },
                                ]),

                            Forms\Components\TextInput::make('min_servers')
                                ->numeric()
                                ->default(0)
                                ->minValue(0)
                                ->required()
                                ->live(onBlur: true)
                                ->rules([
                                    fn (Get $get) => function (
                                        string $attribute,
                                               $value,
                                        Closure $fail
                                    ) use ($get) {
                                        $nodeId = $get('node_id');
                                        $start = (int) $get('port_range_start');
                                        $end = (int) $get('port_range_end');
                                        $minServers = (int) $value;

                                        if (!$nodeId || !$start || !$end) {
                                            return;
                                        }

                                        if ($start > $end) {
                                            return;
                                        }

                                        $totalAllocations = Allocation::query()
                                            ->where('node_id', $nodeId)
                                            ->whereBetween('port', [$start, $end])
                                            ->count();

                                        if ($minServers > $totalAllocations) {
                                            $fail(
                                                "Minimum servers is set to $minServers, " .
                                                "but this range only contains $totalAllocations allocation(s)."
                                            );
                                        }
                                    },
                                ]),

                            TextEntry::make('allocation_status')
                                ->label('Allocation Status')
                                ->state(function (Get $get): string {
                                    $nodeId = $get('node_id');
                                    $start = (int) $get('port_range_start');
                                    $end = (int) $get('port_range_end');
                                    $minServers = (int) ($get('min_servers') ?? 0);

                                    if (!$nodeId || !$start || !$end) {
                                        return 'Select a node and enter a port range.';
                                    }

                                    if ($start > $end) {
                                        return '❌ The start port cannot be greater than the end port.';
                                    }

                                    $query = Allocation::query()
                                        ->where('node_id', $nodeId)
                                        ->whereBetween('port', [$start, $end]);

                                    $total = (clone $query)->count();

                                    if ($total === 0) {
                                        return "❌ No allocations exist on this node between $start and $end.";
                                    }

                                    $free = (clone $query)
                                        ->whereNull('server_id')
                                        ->count();

                                    $used = $total - $free;

                                    if ($minServers > $total) {
                                        return "❌ $total allocation(s) found, but min_servers is set to $minServers.";
                                    }

                                    if ($minServers > $free) {
                                        return "⚠️ $total allocation(s) found — $free free, $used in use. " .
                                            "min_servers is $minServers, so there are currently not enough free allocations.";
                                    }

                                    return "✅ $total allocation(s) found — $free free, $used in use. " .
                                        "min_servers $minServers can be satisfied.";
                                })
                                ->columnSpanFull(),

                            Forms\Components\Toggle::make('auto_creation')
                                ->inline(false)
                                ->columnSpanFull(),
                        ]),
                    Tab::make('Files')
                        ->icon('tabler-folder')
                        ->schema([
                            Livewire::make(
                                TemplateFileManager::class,
                                fn ($record) => [
                                    'templateId' => $record->id,
                                ]
                            ),
                        ]),
                ]),
        ]);
    }

    protected function afterCreate(): void
    {
        /** @var DynamicTemplate $record */
        $record = $this->record;

        if (!$record->auto_creation) {
            return;
        }

        AutoScaleDynamicTemplate::dispatch(
            $record->getKey()
        );
    }
    protected function getHeaderActions(): array
    {
        return [
            Action::make('delete')
                ->hiddenLabel()
                ->color('danger')
                ->tooltip('Delete')
                ->icon('tabler-trash')
                ->requiresConfirmation()
                ->action(function () {
                    $this->record->delete();

                    $this->redirect(DynamicTemplateResource::getUrl('index'));
                }),

            Action::make('save')
                ->hiddenLabel()
                ->tooltip('Save')
                ->icon('tabler-device-floppy')
                ->keyBindings(['mod+s'])
                ->action('save'),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
