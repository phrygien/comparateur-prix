<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<div class="mx-auto max-w-5xl">
    <x-header title="Créer la liste à comparer" separator>
        <x-slot:middle class="!justify-end">
        </x-slot:middle>
        <x-slot:actions>
            <x-button class="btn-error" label="Annuler" />
            <x-button class="btn-primary" label="Valider" />
        </x-slot:actions>
    </x-header>


    <fieldset class="fieldset bg-base-200 border-base-300 rounded-box w-xs border p-4">
        <legend class="fieldset-legend">Libelle</legend>
        <input type="text" class="input" placeholder="" />
        <p class="label">Le libellé de cette liste</p>
    </fieldset>


</div>