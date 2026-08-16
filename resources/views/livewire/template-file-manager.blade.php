@php
    $parts = array_values(
        array_filter(
            explode('/', $currentPath)
        )
    );
@endphp

<div
    x-data="{
        uploading: false,
        dragging: false,
        dragDepth: 0,
        progress: 0,
        dirty: false,

        browse() {
            this.$refs.fileInput.click()
        },

        dragEnter() {
            this.dragDepth++
            this.dragging = true
        },

        dragLeave() {
            this.dragDepth--

            if (this.dragDepth <= 0) {
                this.dragDepth = 0
                this.dragging = false
            }
        },

        resetDrag() {
            this.dragDepth = 0
            this.dragging = false
        },

        uploadDroppedFiles(files) {
            const droppedFiles = Array.from(files ?? [])

            this.resetDrag()

            if (droppedFiles.length === 0) {
                return
            }

            this.uploading = true
            this.progress = 0

            $wire.uploadMultiple(
                'newFiles',
                droppedFiles,
                () => {
                    $wire.storeUploadedFiles().then(() => {
                        this.uploading = false
                        this.progress = 0
                    })
                },
                () => {
                    this.uploading = false
                    this.progress = 0
                },
                (event) => {
                    this.progress = event?.detail?.progress ?? event?.progress ?? 0
                }
            )
        },

        saveEditor() {
            $wire.saveFile().then(() => {
                this.dirty = false
            })
        },

        closeEditor() {
            if (this.dirty && !confirm('You have unsaved changes. Discard them?')) {
                return
            }

            this.dirty = false
            $wire.closeEditor()
        }
    }"
>
    <div class="mb-4 flex items-center justify-between gap-4">
        <div class="flex items-center gap-1 text-sm text-gray-500">
            <button
                type="button"
                class="font-medium text-primary-600 hover:underline"
                wire:click="goTo('')"
            >
                /
            </button>

            @foreach ($parts as $index => $part)
                @php
                    $targetPath = implode(
                        '/',
                        array_slice(
                            $parts,
                            0,
                            $index + 1
                        )
                    );
                @endphp

                <span>/</span>

                <button
                    type="button"
                    class="font-medium text-primary-600 hover:underline"
                    wire:click="goTo({{ \Illuminate\Support\Js::from($targetPath) }})"
                >
                    {{ $part }}
                </button>
            @endforeach

            @if ($editingFile)
                <span>/</span>

                <span class="font-medium text-gray-700 dark:text-gray-200">
                    {{ $editingFile }}
                </span>
            @endif
        </div>

        <div class="flex items-center gap-2">
            @if ($editingFile)
                <x-filament::button
                    type="button"
                    color="gray"
                    icon="tabler-x"
                    size="sm"
                    x-on:click="closeEditor()"
                >
                    Close
                </x-filament::button>

                <x-filament::button
                    type="button"
                    color="primary"
                    icon="tabler-device-floppy"
                    size="sm"
                    x-on:click="saveEditor()"
                >
                    Save
                </x-filament::button>
            @else
                <input
                    x-ref="fileInput"
                    type="file"
                    multiple
                    class="hidden"
                    wire:model="newFiles"
                    x-on:livewire-upload-start="uploading = true; progress = 0"
                    x-on:livewire-upload-finish="$wire.storeUploadedFiles().then(() => { uploading = false; progress = 0 })"
                    x-on:livewire-upload-error="uploading = false; progress = 0"
                    x-on:livewire-upload-progress="progress = $event.detail.progress"
                />

                <x-filament::button
                    type="button"
                    color="success"
                    icon="tabler-upload"
                    size="sm"
                    x-on:click="browse()"
                >
                    Upload
                </x-filament::button>
            @endif
        </div>
    </div>

    @if ($editingFile)
        <div
            class="overflow-hidden rounded-xl ring-1 ring-gray-950/10 dark:ring-white/10"
            x-on:keydown.window="if (($event.ctrlKey || $event.metaKey) && $event.key.toLowerCase() === 's') { $event.preventDefault(); saveEditor() }"
        >
            <div
                class="
                    flex items-center justify-between
                    border-b border-gray-200
                    bg-gray-50 px-4 py-2
                    text-sm
                    dark:border-white/10
                    dark:bg-white/5
                "
            >
                <div class="flex items-center gap-2">
                    <x-filament::icon
                        icon="tabler-file-code"
                        class="h-4 w-4"
                    />

                    <span class="font-medium">
                        {{ $editingFile }}
                    </span>

                    <span
                        x-show="dirty"
                        x-cloak
                        class="text-warning-600"
                    >
                        Unsaved changes
                    </span>
                </div>

                <span class="text-xs text-gray-500">
                    Ctrl+S to save
                </span>
            </div>

            <textarea
                wire:model.defer="editorContent"
                x-on:input="dirty = true"
                spellcheck="false"
                class="
                    block min-h-[600px] w-full resize-y
                    border-0 bg-white
                    p-4 font-mono text-sm leading-6
                    text-gray-950
                    outline-none ring-0
                    focus:ring-0
                    dark:bg-gray-950
                    dark:text-white
                "
            ></textarea>
        </div>
    @else
        <div
            class="relative"
            x-on:dragenter.prevent.stop="dragEnter()"
            x-on:dragover.prevent.stop
            x-on:dragleave.prevent.stop="dragLeave()"
            x-on:drop.prevent.stop="uploadDroppedFiles($event.dataTransfer.files)"
        >
            <div
                x-show="uploading"
                x-cloak
                class="mb-4 rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10"
            >
                <div class="mb-2 flex items-center justify-between text-sm">
                    <span>Uploading...</span>
                    <span x-text="progress + '%'"></span>
                </div>

                <div class="h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                    <div
                        class="h-full bg-primary-600 transition-all"
                        x-bind:style="'width: ' + progress + '%'"
                    ></div>
                </div>
            </div>

            <div
                x-show="dragging"
                x-cloak
                x-transition.opacity.duration.100ms
                class="
        pointer-events-none
        absolute inset-0 z-50
        flex items-center justify-center
        rounded-xl
        border-2 border-dashed border-primary-500
    "
                style="
        background: rgba(3, 7, 18, 0.88);
        backdrop-filter: blur(3px);
        -webkit-backdrop-filter: blur(3px);
    "
            >
                <div class="text-center">
                    <x-filament::icon
                        icon="tabler-cloud-upload"
                        class="mx-auto mb-3 h-12 w-12 text-primary-500"
                    />

                    <div class="text-lg font-semibold text-white">
                        Drop files to upload
                    </div>

                    <div class="mt-1 text-sm text-gray-300">
                        Upload to
                        <span class="font-medium text-white">
                /{{ $currentPath }}
            </span>
                    </div>
                </div>
            </div>

            {{ $this->table }}
        </div>
    @endif

    <x-filament-actions::modals />
</div>
