<?php

use Livewire\Volt\Component;

new class extends Component {
    
    public int $id;

    public function mount($id): void
    {
        $this->id = $id;
    }
}; ?>

<div>
    {{ $id }}
</div>
