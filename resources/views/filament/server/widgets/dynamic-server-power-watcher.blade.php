<x-filament::widget class="hidden">
    @script
    <script>
        Livewire.on('setServerState', ({ state, uuid }) => {
            if (!['stop', 'kill'].includes(state)) {
                return
            }

            $wire.dispatchSelf(
                'dynamicserver-power-action',
                {
                    state: state,
                    uuid: uuid
                }
            )
        })
    </script>
    @endscript
</x-filament::widget>
