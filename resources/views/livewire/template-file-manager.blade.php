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
        progress: 0,
        browse() {
            this.$refs.fileInput.click()
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
                        array_slice($parts, 0, $index + 1)
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
        </div>

        <div>
            <input
                x-ref="fileInput"
                type="file"
                class="hidden"
                wire:model="newFile"
                x-on:livewire-upload-start="uploading = true; progress = 0;"
                x-on:livewire-upload-finish="uploading = false; $wire.storeUploadedFile('');"
                x-on:livewire-upload-error="uploading = false;"
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
        </div>
    </div>

    <div
        x-show="uploading"
        x-cloak
        class="mb-4 rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10"
    >
        <div class="mb-2 flex justify-between text-sm">
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

    {{ $this->table }}

    <x-filament-actions::modals />
</div>
