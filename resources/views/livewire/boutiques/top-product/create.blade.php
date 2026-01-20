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
                placeholder="Ex: Comparaison ..."
            />
            @error('libelle')
                <p class="text-error text-sm mt-1">{{ $message }}</p>
            @enderror
            <p class="label">Le libellé de cette liste</p>
        </fieldset>

        <fieldset class="fieldset bg-base-200 border-base-300 rounded-box w-full border p-4">
            <legend class="fieldset-legend">EAN à comparer</legend>
            <p class="label mb-4">Ajoutez les EAN des produits que vous souhaitez comparer</p>
            
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
                            <x-button  wire:click="removeSku({{ $index }})" label="Supprimer ce EAN" icon-right="o-x-circle" />
                        @endif
                    </div>
                @endforeach
            </div>
            
            <div class="mt-4">
                <x-button 
                    type="button"
                    class="btn-outline btn-sm"
                    icon="o-plus"
                    label="Ajouter un EAN à la liste à comparer"
                    wire:click="addSku"
                />
            </div>
            
            <p class="label mt-4">Vous pouvez ajouter autant de SKUs que nécessaire</p>
        </fieldset>
    </form>
</div>