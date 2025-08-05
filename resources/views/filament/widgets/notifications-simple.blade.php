<x-filament-widgets::widget class="fi-wi-notifications">
    <x-filament::section>
        <div>
            <h3>Powiadomienia</h3>
            <p>Zadania: {{ $newTasksCount ?? 0 }}</p>
            <p>Wiadomości: {{ $unreadMessagesCount ?? 0 }}</p>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
