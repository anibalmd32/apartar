<x-pulse>
    <livewire:monitor.platform-health cols="8" />

    <x-pulse::card cols="4">
        <x-pulse::card-header name="Más herramientas">
            <x-slot:icon>
                <x-pulse::icons.command-line />
            </x-slot:icon>
        </x-pulse::card-header>

        <ul class="flex flex-col gap-2 text-sm">
            <li><a class="font-semibold text-purple-600 hover:underline dark:text-purple-400" href="{{ url('/'.config('telescope.path', 'telescope')) }}">Telescope: trazas de peticiones, consultas, jobs y correos</a></li>
            <li><a class="font-semibold text-purple-600 hover:underline dark:text-purple-400" href="{{ url('/'.config('log-viewer.route_path', 'logs')) }}">Logs de Laravel</a></li>
            <li>
                <form method="POST" action="{{ route('monitor.logout') }}">
                    @csrf
                    <button type="submit" class="text-gray-500 hover:underline dark:text-gray-400">Salir del monitor</button>
                </form>
            </li>
        </ul>
    </x-pulse::card>

    <livewire:pulse.servers cols="full" />

    <livewire:pulse.queues cols="6" />

    <livewire:pulse.cache cols="6" />

    <livewire:pulse.slow-queries cols="8" />

    <livewire:pulse.exceptions cols="4" />

    <livewire:pulse.slow-requests cols="6" />

    <livewire:pulse.slow-jobs cols="6" />

    <livewire:pulse.slow-outgoing-requests cols="full" />
</x-pulse>
