<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<div class="mx-auto max-w-5xl">
    <x-header title="Creer la liste a commpararer" separator>
        <x-slot:middle class="!justify-end">
        </x-slot:middle>
        <x-slot:actions>
            <x-button class="btn-error" label="Annuler" />
            <x-button class="btn-primary" label="Valider" />
        </x-slot:actions>
    </x-header>
</div>