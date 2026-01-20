<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<div class="mx-auto max-w-5xl">

    <!-- Header section !-->
    <x-header title="Historique des comparaisons" subtitle="Toutes les comparaisons déjà effectuées" no-separator>
        <x-slot:middle class="!justify-end">

        </x-slot:middle>
        <x-slot:actions>
            <x-button icon="o-plus-circle" class="btn-primary uppercase" label="Créer une liste à comparer" />
        </x-slot:actions>
    </x-header>

    <x-card subtitle="Historique de comparaison">
        <livewire:boutiques.historique-comparaison />
    </x-card>
</div>