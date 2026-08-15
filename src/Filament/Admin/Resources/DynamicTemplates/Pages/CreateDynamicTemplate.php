<?php

namespace ByPixelTV\Dynamicservers\Filament\Admin\Resources\DynamicTemplates\Pages;

use App\Models\Allocation;
use App\Models\Egg;
use ByPixelTV\Dynamicservers\Filament\Admin\Resources\DynamicTemplateResource;
use ByPixelTV\Dynamicservers\Jobs\AutoScaleDynamicTemplate;
use ByPixelTV\Dynamicservers\Models\DynamicTemplate;
use Closure;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconSize;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

class CreateDynamicTemplate extends CreateRecord
{
    protected static string $resource = DynamicTemplateResource::class;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Wizard::make([
                Step::make('General')
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
                            ->default(fn () => auth()->id())
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

                Step::make('Resources')
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
                                    ->default(true)
                                    ->live()
                                    ->dehydrated(false)
                                    ->afterStateUpdated(fn (Set $set) => $set('cpu', 0))
                                    ->options([true => 'Unlimited', false => 'Limited'])
                                    ->colors([true => 'primary', false => 'warning'])
                                    ->columnSpan(2),

                                Forms\Components\TextInput::make('cpu')
                                    ->label('CPU Limit')->inlineLabel()
                                    ->hidden(fn (Get $get) => $get('unlimited_cpu'))
                                    ->dehydratedWhenHidden()
                                    ->numeric()->suffix('%')->default(0)
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

                Step::make('Scaling')
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
            ])
                ->columnSpanFull()
                ->nextAction(fn (Action $action) => $action
                    ->iconButton()
                    ->iconSize(IconSize::ExtraLarge)
                    ->icon('tabler-arrow-right'))
                ->previousAction(fn (Action $action) => $action
                    ->iconButton()
                    ->iconSize(IconSize::ExtraLarge)
                    ->icon('tabler-arrow-left'))
                ->submitAction(new HtmlString(Blade::render(<<<'BLADE'
                    <x-filament::icon-button
                        type="submit"
                        iconSize="xl"
                        icon="tabler-plus"
                        tooltip="Create"
                    >
                        Create
                    </x-filament::icon-button>
                BLADE))),
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

    protected function getFormActions(): array
    {
        return [];
    }
}
