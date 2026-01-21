<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<div class="mx-auto max-w-5xl">

    <!-- Header section !-->
    <x-header title="Historique des créations de listes" subtitle="Toutes les listes déjà créées" no-separator>
        <x-slot:middle class="!justify-end">

        </x-slot:middle>
        <x-slot:actions>
            <x-button link="/top-product/create" icon="o-plus-circle" class="btn-primary uppercase" label="Créer une liste à comparer" />
        </x-slot:actions>
    </x-header>

    <x-card>
        <livewire:boutiques.historique-comparaison />
    </x-card>
</div>