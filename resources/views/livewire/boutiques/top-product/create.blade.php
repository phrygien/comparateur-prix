<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Validate;

new class extends Component {
    #[Validate('required|min:3|max:255')]
    public string $libelle = '';
    
    public array $skus = [
        ['sku' => '']
    ];
    
    public function addSku()
    {
        $this->skus[] = ['sku' => ''];
    }
    
    public function removeSku($index)
    {
        if (count($this->skus) > 1) {
            unset($this->skus[$index]);
            $this->skus = array_values($this->skus);
        }
    }
    
    public function save()
    {
        $this->validate();
        
        // Filtrer les SKU vides
        $validSkus = array_filter(array_column($this->skus, 'sku'));
        
        // Ici vous pouvez ajouter la logique pour sauvegarder
        // dd([
        //     'libelle' => $this->libelle,
        //     'skus' => $validSkus
        // ]);
        
        session()->flash('success', 'Liste créée avec succès !');
    }
    
    public function cancel()
    {
        return redirect()->to('/'); // Rediriger vers la page d'accueil ou autre
    }
}; ?>

<div class="mx-auto max-w-5xl">
    <x-header title="Créer la liste à comparer" separator>
        <x-slot:middle class="!justify-end">
        </x-slot:middle>
        <x-slot:actions>
            <x-button 
                class="btn-error" 
                label="Annuler" 
                wire:click="cancel"
                wire:confirm="Êtes-vous sûr de vouloir annuler ?"
            />
            <x-button 
                class="btn-primary" 
                label="Valider" 
                wire:click="save" 
            />
        </x-slot:actions>
    </x-header>

    <form wire:submit.prevent="save" class="space-y-6">
        <fieldset class="fieldset bg-base-200 border-base-300 rounded-box w-full border p-4">
            <legend class="fieldset-legend">Libellé</legend>
            <input 
                type="text" 
                class="input @error('libelle') input-error @enderror" 
                wire:model="libelle"
                placeholder="Ex: Comparaison smartphones 2024"
            />
            @error('libelle')
                <p class="text-error text-sm mt-1">{{ $message }}</p>
            @enderror
            <p class="label">Le libellé de cette liste</p>
        </fieldset>

        <fieldset class="fieldset bg-base-200 border-base-300 rounded-box w-full border p-4">
            <legend class="fieldset-legend">SKUs à comparer</legend>
            <p class="label mb-4">Ajoutez les SKUs des produits que vous souhaitez comparer</p>
            
            <div class="space-y-4">
                @foreach($skus as $index => $sku)
                    <div class="flex items-center gap-2" wire:key="sku-{{ $index }}">
                        <div class="flex-1">
                            <input 
                                type="text" 
                                class="input w-full" 
                                wire:model="skus.{{ $index }}.sku"
                                placeholder="Ex: 121315454578"
                            />
                        </div>
                        
                        @if(count($skus) > 1)
                        <x-button  wire:click="removeSku({{ $index }})" label="Supprimer ce SKU" icon-right="o-x-circle" />
                            {{-- <button 
                                type="button"
                                class="btn btn-ghost btn-sm btn-square"
                                wire:click="removeSku({{ $index }})"
                                title="Supprimer ce SKU"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                                </svg>
                            </button> --}}
                        @endif
                    </div>
                @endforeach
            </div>
            
            <div class="mt-4">
                <x-button 
                    type="button"
                    class="btn-outline btn-sm"
                    icon="o-plus"
                    label="Ajouter un SKU à la liste à comparer"
                    wire:click="addSku"
                />
            </div>
            
            <p class="label mt-4">Vous pouvez ajouter autant de SKUs que nécessaire</p>
        </fieldset>
    </form>
</div>