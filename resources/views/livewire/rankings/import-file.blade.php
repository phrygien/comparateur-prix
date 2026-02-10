<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<div class="max-w-7xl">
    <x-header title="Importer" subtitle="Importer le fichier de top product" separator />

    <x-file wire:model="file" label="Ranking File" hint="Format xlsx" accept="application/xlsx" />
</div>
