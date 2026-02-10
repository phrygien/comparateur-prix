<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<div class="max-w-7xl">
    <x-header title="Importer" subtitle="Importer le fichier de top product" separator />


    <x-form wire:submit="importer">

            <x-file wire:model="file" label="Ranking File" hint="Format xlsx" accept="application/xlsx" />
        <x-slot:actions>
            <x-button label="Importer" class="btn-primary" type="submit" spinner="importer" />
        </x-slot:actions>
    </x-form>

</div>
