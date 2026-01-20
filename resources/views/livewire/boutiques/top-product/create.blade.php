<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<div class="mx-auto max-w-5xl">
    <x-header title="Creer la liste a commpararer" separator>
        <x-slot:middle class="!justify-end">
            <x-input icon="o-bolt" placeholder="Search..." />
        </x-slot:middle>
        <x-slot:actions>
            <x-button icon="o-funnel" />
            <x-button icon="o-plus" class="btn-primary" />
        </x-slot:actions>
    </x-header>
</div>