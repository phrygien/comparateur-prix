<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<div class="w-full">
    <x-header title="Ranking" subtitle="Comparaison des prix des produits classés" separator>

        <x-slot:middle class="!justify-end">
        </x-slot:middle>
        <x-slot:actions>
            <x-button
                icon="o-arrow-down-tray"
                label="Exporter résultat"
                class="btn-primary"
            />
        </x-slot:actions>
    </x-header>
</div>
