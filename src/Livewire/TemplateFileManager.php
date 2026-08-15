<?php

namespace ByPixelTV\Dynamicservers\Livewire;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\IconSize;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class TemplateFileManager extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use WithFileUploads;
    public int $templateId;

    public string $currentPath = '';

    public $newFile = null;

    protected function rootPath(): string
    {
        return "dynamic-templates-tree/$this->templateId";
    }

    protected function fullPath(string $relative = ''): string
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');

        return $relative === ''
            ? $this->rootPath()
            : $this->rootPath() . '/' . $relative;
    }

    public function mount(int $templateId): void
    {
        $this->templateId = $templateId;

        Storage::disk('local')->makeDirectory(
            $this->rootPath()
        );
    }

    protected function getEntries(): Collection
    {
        $disk = Storage::disk('local');

        $path = $this->fullPath(
            $this->currentPath
        );

        $folders = collect(
            $disk->directories($path)
        )->map(function ($path) use ($disk) {
            return [
                'id' => 'folder:' . basename($path),
                'name' => basename($path),
                'is_directory' => true,
                'size' => null,
                'modified_at' => $this->getModified($path),
            ];
        });

        $files = collect(
            $disk->files($path)
        )->map(function ($path) use ($disk) {
            return [
                'id' => 'file:' . basename($path),
                'name' => basename($path),
                'is_directory' => false,
                'size' => $disk->size($path),
                'modified_at' => $this->getModified($path),
            ];
        });

        return $folders
            ->merge($files)
            ->sortBy([
                ['is_directory', 'desc'],
                ['name', 'asc'],
            ])
            ->values();
    }

    protected function getModified(
        string $path
    ): ?Carbon {
        try {
            return Carbon::createFromTimestamp(
                Storage::disk('local')
                    ->lastModified($path)
            );
        } catch (Throwable) {
            return null;
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(
                fn () => $this->getEntries()
            )
            ->paginated(false)

            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->icon(
                        fn (array $record) =>
                        $record['is_directory']
                            ? 'tabler-folder'
                            : $this->getFileIcon($record['name'])
                    )
                    ->extraAttributes([
                        'class' => '[&_.fi-ta-icon]:size-4',
                    ])
                    ->weight('medium'),

                TextColumn::make('size')
                    ->label('Size')
                    ->visibleFrom('md')
                    ->formatStateUsing(
                        fn ($state, array $record) =>
                        $record['is_directory']
                            ? null
                            : $this->formatBytes(
                            $state
                        )
                    ),

                TextColumn::make('modified_at')
                    ->label('Modified')
                    ->visibleFrom('md')
                    ->since(),
            ])

            ->recordAction(
                fn (array $record) =>
                $record['is_directory']
                    ? 'open'
                    : null
            )

            ->recordActions([
                Action::make('open')
                    ->hiddenLabel()
                    ->tooltip('Open')
                    ->icon('tabler-eye')
                    ->iconSize(IconSize::Small)
                    ->visible(
                        fn (array $record) =>
                        $record['is_directory']
                    )
                    ->action(
                        fn (array $record) =>
                        $this->openFolder(
                            $record['name']
                        )
                    ),

                Action::make('download')
                    ->hiddenLabel()
                    ->tooltip('Download')
                    ->icon('tabler-download')
                    ->iconSize(IconSize::Small)
                    ->visible(
                        fn (array $record) =>
                        !$record['is_directory']
                    )
                    ->action(
                        fn (array $record) =>
                        $this->downloadFile(
                            $record['name']
                        )
                    ),

                Action::make('delete')
                    ->hiddenLabel()
                    ->tooltip('Delete')
                    ->icon('tabler-trash')
                    ->iconSize(IconSize::Small)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (array $record) {
                        if ($record['is_directory']) {
                            $this->deleteFolder(
                                $record['name']
                            );
                        } else {
                            $this->deleteFile(
                                $record['name']
                            );
                        }
                    }),
            ])

            ->headerActions([
                Action::make('back')
                    ->hiddenLabel()
                    ->tooltip('Back')
                    ->icon('tabler-arrow-left')
                    ->iconSize(IconSize::Small)
                    ->color('gray')
                    ->visible(fn () => $this->currentPath !== '')
                    ->action(fn () => $this->goBack()),

                Action::make('new_folder')
                    ->hiddenLabel()
                    ->tooltip('New folder')
                    ->icon('tabler-folder-plus')
                    ->iconSize(IconSize::Small)
                    ->schema([
                        TextInput::make('name')
                            ->label('Folder name')
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        $this->createFolder($data['name']);
                    }),
            ]);
    }

    public function openFolder(
        string $name
    ): void {
        $this->currentPath = trim(
            $this->currentPath . '/' . $name,
            '/'
        );

        $this->resetTable();
    }

    public function goBack(): void
    {
        if ($this->currentPath === '') {
            return;
        }

        $parts = array_values(
            array_filter(
                explode('/', $this->currentPath)
            )
        );

        array_pop($parts);

        $this->currentPath = implode('/', $parts);

        $this->resetTable();
    }

    public function goTo(
        string $path
    ): void {
        $this->currentPath = trim(
            $path,
            '/'
        );

        $this->resetTable();
    }

    public function createFolder(
        string $name
    ): void {
        $name = trim($name);

        if (
            $name === '' ||
            str_contains($name, '/') ||
            str_contains($name, '\\') ||
            $name === '..'
        ) {
            return;
        }

        Storage::disk('local')
            ->makeDirectory(
                $this->fullPath(
                    trim(
                        $this->currentPath .
                        '/' .
                        $name,
                        '/'
                    )
                )
            );

        Notification::make()
            ->title('Folder created')
            ->success()
            ->send();

        $this->resetTable();
    }

    public function createFolderPath(
        string $path
    ): void {
        $path = trim(
            str_replace('\\', '/', $path),
            '/'
        );

        if (
            $path === '' ||
            str_contains($path, '..')
        ) {
            return;
        }

        Storage::disk('local')
            ->makeDirectory(
                $this->fullPath(
                    trim(
                        $this->currentPath .
                        '/' .
                        $path,
                        '/'
                    )
                )
            );

        $this->resetTable();
    }

    public function storeUploadedFile(
        string $relativePath = ''
    ): void {
        $this->validate([
            'newFile' =>
                'required|file|max:51200',
        ]);

        $relativePath = trim(
            str_replace(
                '\\',
                '/',
                $relativePath
            ),
            '/'
        );

        if (
            str_contains(
                $relativePath,
                '..'
            )
        ) {
            abort(422);
        }

        $directory = trim(
            $this->currentPath .
            '/' .
            $relativePath,
            '/'
        );

        Storage::disk('local')
            ->makeDirectory(
                $this->fullPath(
                    $directory
                )
            );

        $name =
            $this->newFile
                ->getClientOriginalName();

        $this->newFile->storeAs(
            $this->fullPath(
                $directory
            ),
            $name,
            'local'
        );

        $this->newFile = null;

        $this->resetTable();
    }

    public function deleteFile(
        string $name
    ): void {
        Storage::disk('local')
            ->delete(
                $this->fullPath(
                    trim(
                        $this->currentPath .
                        '/' .
                        $name,
                        '/'
                    )
                )
            );

        $this->resetTable();
    }

    public function deleteFolder(
        string $name
    ): void {
        Storage::disk('local')
            ->deleteDirectory(
                $this->fullPath(
                    trim(
                        $this->currentPath .
                        '/' .
                        $name,
                        '/'
                    )
                )
            );

        $this->resetTable();
    }

    public function downloadFile(
        string $name
    ): StreamedResponse
    {
        return Storage::disk('local')
            ->download(
                $this->fullPath(
                    trim(
                        $this->currentPath .
                        '/' .
                        $name,
                        '/'
                    )
                )
            );
    }

    protected function formatBytes(
        ?int $bytes
    ): string {
        if (!$bytes) {
            return '0 B';
        }

        $units = [
            'B',
            'KB',
            'MB',
            'GB',
        ];

        $power = min(
            floor(
                log(
                    $bytes,
                    1024
                )
            ),
            count($units) - 1
        );

        return number_format(
                $bytes /
                (1024 ** $power),
                2
            ) .
            ' ' .
            $units[$power];
    }

    protected function getFileIcon(
        string $name
    ): string {
        return match (
        strtolower(
            pathinfo(
                $name,
                PATHINFO_EXTENSION
            )
        )
        ) {
            'zip',
            'tar',
            'gz',
            'rar',
            '7z'
            => 'tabler-file-zip',

            'png',
            'jpg',
            'jpeg',
            'gif',
            'webp'
            => 'tabler-photo',

            'json',
            'yml',
            'yaml'
            => 'tabler-braces',

            'php',
            'js',
            'ts',
            'html',
            'css',
            'java'
            => 'tabler-file-code',

            'txt',
            'md',
            'log'
            => 'tabler-file-text',

            default
            => 'tabler-file',
        };
    }

    public function getUploadSizeLimit(): int
    {
        return 50 * 1024 * 1024;
    }

    public function render(): View
    {
        return view(
            'dynamicservers::livewire.template-file-manager'
        );
    }
}
