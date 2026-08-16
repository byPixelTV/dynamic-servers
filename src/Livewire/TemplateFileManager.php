<?php

namespace ByPixelTV\Dynamicservers\Livewire;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\Alignment;
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
use App\Enums\EditorLanguages;
use App\Filament\Components\Forms\Fields\MonacoEditor;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class TemplateFileManager extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use WithFileUploads;
    public int $templateId;

    public string $currentPath = '';

    public array $newFiles = [];

    public ?string $editingFile = null;

    public ?array $editorData = [];

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

    public function editorForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('editorData')
            ->components([
                Section::make(
                    $this->editingFile
                        ? 'Edit ' . $this->editingFile
                        : 'Edit file'
                )
                    ->footerActions([
                        Action::make('save_close_file')
                            ->label('Save & Close')
                            ->icon('tabler-door-exit')
                            ->keyBindings('mod+shift+s')
                            ->action(function () {
                                $this->saveFile();
                                $this->closeEditor();
                            }),

                        Action::make('save_file')
                            ->label('Save')
                            ->icon('tabler-device-floppy')
                            ->keyBindings('mod+s')
                            ->action(
                                fn () => $this->saveFile()
                            ),

                        Action::make('cancel_edit_file')
                            ->label('Cancel')
                            ->color('danger')
                            ->icon('tabler-x')
                            ->action(
                                fn () => $this->closeEditor()
                            ),
                    ])
                    ->footerActionsAlignment(
                        Alignment::End
                    )
                    ->schema([
                        Select::make('lang')
                            ->label('Syntax')
                            ->searchable()
                            ->live()
                            ->options(EditorLanguages::class)
                            ->selectablePlaceholder(false)
                            ->afterStateUpdated(
                                fn ($state) =>
                                $this->dispatch(
                                    'setLanguage',
                                    lang: $state
                                )
                            ),

                        MonacoEditor::make('editor')
                            ->hiddenLabel()
                            ->language(
                                fn (Get $get) =>
                                $get('lang')
                            ),
                    ])
                    ->columnSpanFull(),
            ]);
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

            ->recordAction(function (array $record): ?string {
                if ($record['is_directory']) {
                    return 'open';
                }

                if ($this->isEditableFile($record['name'])) {
                    return 'edit';
                }

                return null;
            })

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

                Action::make('edit')
                    ->hiddenLabel()
                    ->tooltip('Edit')
                    ->icon('tabler-pencil')
                    ->iconSize(IconSize::Small)
                    ->visible(
                        fn (array $record) =>
                            !$record['is_directory']
                            && $this->isEditableFile($record['name'])
                    )
                    ->action(
                        fn (array $record) =>
                        $this->openFile($record['name'])
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

                Action::make('rename')
                    ->hiddenLabel()
                    ->tooltip('Rename')
                    ->icon('tabler-pencil')
                    ->iconSize(IconSize::Small)
                    ->schema([
                        TextInput::make('name')
                            ->label('New name')
                            ->required()
                            ->default(
                                fn (array $record) => $record['name']
                            ),
                    ])
                    ->action(function (
                        array $record,
                        array $data
                    ): void {
                        $this->renameEntry(
                            $record['name'],
                            $data['name'],
                            $record['is_directory']
                        );
                    }),

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

                Action::make('new_file')
                    ->hiddenLabel()
                    ->tooltip('New file')
                    ->icon('tabler-file-plus')
                    ->iconSize(IconSize::Small)
                    ->schema([
                        TextInput::make('name')
                            ->label('File name')
                            ->placeholder('config.yml')
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        $this->createFile($data['name']);
                    }),

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
        $this->closeEditor();

        $this->currentPath = trim(
            $this->currentPath . '/' . $name,
            '/'
        );

        $this->resetTable();
    }

    public function goBack(): void
    {
        $this->closeEditor();

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
        $this->closeEditor();

        $this->currentPath = trim(
            $path,
            '/'
        );

        $this->resetTable();
    }

    public function createFile(string $name): void
    {
        $name = trim($name);

        if (!$this->isValidEntryName($name)) {
            Notification::make()
                ->title('Invalid file name')
                ->danger()
                ->send();

            return;
        }

        $path = $this->fullPath(
            trim(
                $this->currentPath . '/' . $name,
                '/'
            )
        );

        $disk = Storage::disk('local');

        if ($disk->exists($path)) {
            Notification::make()
                ->title('File already exists')
                ->warning()
                ->send();

            return;
        }

        $disk->put($path, '');

        Notification::make()
            ->title('File created')
            ->success()
            ->send();

        $this->resetTable();

        if ($this->isEditableFile($name)) {
            $this->openFile($name);
        }
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

    public function storeUploadedFiles(): void
    {
        $this->validate([
            'newFiles' => 'required|array',
            'newFiles.*' => 'required|file|max:104857600',
        ]);

        $directory = trim(
            $this->currentPath,
            '/'
        );

        Storage::disk('local')
            ->makeDirectory(
                $this->fullPath($directory)
            );

        foreach ($this->newFiles as $file) {
            $name = $file->getClientOriginalName();

            if (!$this->isValidEntryName($name)) {
                continue;
            }

            $file->storeAs(
                $this->fullPath($directory),
                $name,
                'local'
            );
        }

        $count = count($this->newFiles);

        $this->newFiles = [];

        Notification::make()
            ->title(
                $count === 1
                    ? 'File uploaded'
                    : "$count files uploaded"
            )
            ->success()
            ->send();

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

    public function renameEntry(
        string $oldName,
        string $newName,
        bool $isDirectory
    ): void {
        $oldName = trim($oldName);
        $newName = trim($newName);

        if (
            !$this->isValidEntryName($oldName)
            || !$this->isValidEntryName($newName)
        ) {
            Notification::make()
                ->title('Invalid name')
                ->danger()
                ->send();

            return;
        }

        if ($oldName === $newName) {
            return;
        }

        $disk = Storage::disk('local');

        $oldRelativePath = trim(
            $this->currentPath . '/' . $oldName,
            '/'
        );

        $newRelativePath = trim(
            $this->currentPath . '/' . $newName,
            '/'
        );

        $oldPath = $this->fullPath($oldRelativePath);
        $newPath = $this->fullPath($newRelativePath);

        if (!$disk->exists($oldPath)) {
            Notification::make()
                ->title('File or folder not found')
                ->danger()
                ->send();

            return;
        }

        if ($disk->exists($newPath)) {
            Notification::make()
                ->title('Name already exists')
                ->body(
                    "An entry named \"{$newName}\" already exists."
                )
                ->warning()
                ->send();

            return;
        }

        try {
            $disk->move(
                $oldPath,
                $newPath
            );

            Notification::make()
                ->title(
                    $isDirectory
                        ? 'Folder renamed'
                        : 'File renamed'
                )
                ->body(
                    "{$oldName} → {$newName}"
                )
                ->success()
                ->send();

            $this->resetTable();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not rename')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
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

    protected function isValidEntryName(string $name): bool
    {
        $name = trim($name);

        return $name !== ''
            && $name !== '.'
            && $name !== '..'
            && !str_contains($name, '/')
            && !str_contains($name, '\\');
    }

    public function isEditableFile(string $name): bool
    {
        if (!$this->isValidEntryName($name)) {
            return false;
        }

        $relativePath = trim(
            $this->currentPath . '/' . $name,
            '/'
        );

        $path = $this->fullPath($relativePath);

        $disk = Storage::disk('local');

        if (!$disk->exists($path)) {
            return false;
        }

        try {
            if ($disk->size($path) > 5 * 1024 * 1024) {
                return false;
            }

            $stream = $disk->readStream($path);

            if ($stream === false) {
                return false;
            }

            $sample = fread($stream, 8192);

            fclose($stream);

            if ($sample === false) {
                return false;
            }

            if ($sample === '') {
                return true;
            }

            if (str_contains($sample, "\0")) {
                return false;
            }

            $length = strlen($sample);
            $controlCharacters = 0;

            for ($i = 0; $i < $length; $i++) {
                $byte = ord($sample[$i]);

                if (
                    $byte < 32
                    && $byte !== 9
                    && $byte !== 10
                    && $byte !== 13
                ) {
                    $controlCharacters++;
                }
            }

            /*
             * If more than 10% of the sample consists of strange
             * control characters, treat it as binary.
             */
            return ($controlCharacters / $length) < 0.10;
        } catch (Throwable) {
            return false;
        }
    }

    public function openFile(string $name): void
    {
        if (
            !$this->isValidEntryName($name)
            || !$this->isEditableFile($name)
        ) {
            Notification::make()
                ->title('File cannot be edited')
                ->danger()
                ->send();

            return;
        }

        $relativePath = trim(
            $this->currentPath . '/' . $name,
            '/'
        );

        $path = $this->fullPath($relativePath);

        $disk = Storage::disk('local');

        if (!$disk->exists($path)) {
            Notification::make()
                ->title('File not found')
                ->danger()
                ->send();

            return;
        }

        $maxEditorSize = 5 * 1024 * 1024;

        if ($disk->size($path) > $maxEditorSize) {
            Notification::make()
                ->title('File too large')
                ->body('Files larger than 5 MB cannot be edited in the browser.')
                ->warning()
                ->send();

            return;
        }

        try {
            $content = $disk->get($path);

            $this->editingFile = $name;

            $extension = pathinfo(
                $name,
                PATHINFO_EXTENSION
            );

            $language = EditorLanguages::fromWithAlias(
                $extension
            );

            $this->editorData = [
                'lang' => $language,
                'editor' => $content,
            ];

            $this->editorForm->fill(
                $this->editorData
            );
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not open file')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function saveFile(): void
    {
        if (!$this->editingFile) {
            return;
        }

        if (!$this->isValidEntryName($this->editingFile)) {
            abort(422);
        }

        $content = $this->editorData['editor'] ?? '';

        if (strlen($content) > 5 * 1024 * 1024) {
            Notification::make()
                ->title('File too large')
                ->body('The editor is limited to 5 MB.')
                ->danger()
                ->send();

            return;
        }

        $relativePath = trim(
            $this->currentPath
            . '/'
            . $this->editingFile,
            '/'
        );

        try {
            Storage::disk('local')->put(
                $this->fullPath($relativePath),
                $content
            );

            Notification::make()
                ->title('File saved')
                ->body($this->editingFile)
                ->success()
                ->send();

            $this->resetTable();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not save file')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function closeEditor(): void
    {
        $this->editingFile = null;

        $this->editorData = [];
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
        return 100 * 1024 * 1024 * 1024;
    }

    public function render(): View
    {
        return view(
            'dynamicservers::livewire.template-file-manager'
        );
    }
}
