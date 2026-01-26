<?php

namespace App\Http\Livewire;

use Livewire\Volt\Component;
use App\Models\Comparaison;
use App\Models\DetailProduct;
use Carbon\Carbon;

new class extends Component {
    public $perPage = 15;
    public $page = 1;
    public $loading = false;
    public $hasMore = true;
    public $comparaisons;
    
    // Pour la confirmation de suppression
    public $showDeleteModal = false;
    public $comparaisonToDelete;
    public $comparaisonToDeleteName;
    
    public function mount()
    {
        $this->comparaisons = collect();
        $this->loadData();
    }
    
    public function loadData()
    {
        if ($this->loading || !$this->hasMore) {
            return;
        }
        
        $this->loading = true;
        
        $newComparaisons = Comparaison::orderBy('created_at', 'desc')
            ->skip(($this->page - 1) * $this->perPage)
            ->take($this->perPage)
            ->get();
            
        $this->comparaisons = $this->comparaisons->concat($newComparaisons);
        
        $totalItems = Comparaison::count();
        $this->hasMore = ($this->page * $this->perPage) < $totalItems;
        
        $this->page++;
        $this->loading = false;
    }
    
    public function loadMore()
    {
        $this->loadData();
    }

    public function formatDateFr($date)
    {
        if (!$date) {
            return '-';
        }
        
        Carbon::setLocale('fr');
        
        return Carbon::parse($date)->isoFormat('D MMMM YYYY [à] HH[h]mm');
    }
    
    // Méthode pour ouvrir le modal de confirmation
    public function confirmDelete($comparaisonId, $comparaisonName)
    {
        $this->comparaisonToDelete = $comparaisonId;
        $this->comparaisonToDeleteName = $comparaisonName;
        $this->showDeleteModal = true;
    }
    
    // Méthode pour supprimer la comparaison et ses détails
    public function deleteComparaison()
    {
        try {
            $comparaison = Comparaison::find($this->comparaisonToDelete);
            
            if ($comparaison) {
                // Supprimer d'abord tous les détails associés
                DetailProduct::where('list_product_id', $comparaison->id)->delete();
                
                // Puis supprimer la comparaison elle-même
                $comparaison->delete();
                
                // Recharger les données
                $this->comparaisons = $this->comparaisons->reject(function ($item) {
                    return $item->id == $this->comparaisonToDelete;
                });
                
                // Fermer le modal
                $this->showDeleteModal = false;
                
                // Réinitialiser les variables
                $this->comparaisonToDelete = null;
                $this->comparaisonToDeleteName = null;
                
                // Émettre une notification de succès
                $this->dispatch('notify', [
                    'type' => 'success',
                    'message' => 'La comparaison et ses détails ont été supprimés avec succès.'
                ]);
            }
        } catch (\Exception $e) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Une erreur est survenue lors de la suppression.'
            ]);
        }
    }
}; ?>

<div 
    x-data="{
        handleScroll() {
            const threshold = 100; // pixels avant la fin
            const position = window.innerHeight + window.scrollY;
            const height = document.documentElement.scrollHeight;
            
            if (position >= height - threshold && !@this.loading && @this.hasMore) {
                @this.loadMore();
            }
        }
    }"
    x-init="window.addEventListener('scroll', handleScroll)"
    class="space-y-4"
>
    <!-- Tableau -->
    <div class="overflow-x-auto">
        <table class="table table-zebra">
            <thead>
                <tr>
                    <th class="uppercase">Libellé</th>
                    {{-- <th class="uppercase">Statut</th> --}}
                    <th class="uppercase">Date de création</th>
                    <th class="uppercase">Dernière modification</th>
                    <th class="uppercase">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($comparaisons as $comparaison)
                <tr wire:key="comparaison-{{ $comparaison->id }}">
                    <td>{{ $comparaison->libelle }}</td>
                    {{-- <td>
                        <span class="badge {{ $comparaison->status ? 'badge-success' : 'badge-error' }}">
                            {{ $comparaison->status ? 'Actif' : 'Inactif' }}
                        </span>
                    </td> --}}
                    <td>
                        {{ $this->formatDateFr($comparaison->created_at) }}
                    </td>
                    <td>
                        {{ $this->formatDateFr($comparaison->updated_at) }}
                    </td>
                    <td>
                        <div class="flex space-x-2">
                            <x-button 
                                wire:navigate 
                                href="{{ route('top-product.show', $comparaison->id) }}" 
                                label="Détails" 
                                class="btn-primary btn-sm" 
                            />
                            <x-button 
                                wire:click="confirmDelete({{ $comparaison->id }}, '{{ addslashes($comparaison->libelle) }}')" 
                                label="Supprimer" 
                                class="btn-error btn-sm" 
                            />
                        </div>
                    </td>
                </tr>
                @endforeach
                
                @if($comparaisons->isEmpty() && !$loading)
                <tr>
                    <td colspan="5" class="text-center py-8 text-gray-500">
                        <div class="flex flex-col items-center">
                            <svg class="w-12 h-12 text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            Aucune comparaison trouvée
                        </div>
                    </td>
                </tr>
                @endif
            </tbody>
        </table>
    </div>

    <!-- Modal de confirmation de suppression -->
    <x-modal wire:model="showDeleteModal" title="Confirmer la suppression" class="backdrop-blur">
        <div class="space-y-4">
            <div class="alert alert-warning alert-soft">
                <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.998-.833-2.732 0L4.732 16.5c-.77.833.192 2.5 1.732 2.5z" />
                </svg>
                <div>
                    <h3 class="font-bold">Attention !</h3>
                    <div class="text-sm">
                        Cette action est irréversible.
                    </div>
                </div>
            </div>

            <p class="text-gray-700">
                Êtes-vous sûr de vouloir supprimer la comparaison 
                <strong>"{{ $comparaisonToDeleteName }}"</strong> ?
            </p>
            
            <p class="text-gray-600 text-sm">
                Tous les détails de produits associés à cette liste seront également supprimés définitivement.
            </p>
        </div>

        <x-slot:actions>
            <x-button label="Annuler" @click="$wire.showDeleteModal = false" />
            <x-button 
                label="Supprimer" 
                class="btn-error" 
                wire:click="deleteComparaison" 
                wire:loading.attr="disabled"
            >
                <span wire:loading.remove>Supprimer</span>
                <span wire:loading>
                    <span class="loading loading-spinner loading-xs"></span>
                    Suppression...
                </span>
            </x-button>
        </x-slot:actions>
    </x-modal>

    <!-- Indicateur de chargement -->
    @if($loading)
    <div class="text-center py-6">
        <div class="inline-block animate-spin rounded-full h-10 w-10 border-t-2 border-b-2 border-blue-500"></div>
        <p class="mt-2 text-gray-600">Chargement des données...</p>
    </div>
    @endif

    <!-- Bouton de chargement manuel (optionnel) -->
    @if($hasMore && !$loading)
    <div class="text-center py-4">
        <button 
            wire:click="loadMore" 
            class="btn btn-outline btn-primary"
            :disabled="$wire.loading"
        >
            Charger plus
        </button>
    </div>
    @endif

    <!-- Message de fin -->
    @if(!$hasMore && $comparaisons->isNotEmpty())
    <div class="alert alert-info alert-soft shadow-lg">
        <div>
            <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current flex-shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            <span>Toutes les comparaisons ont été chargées ({{ $comparaisons->count() }} éléments).</span>
        </div>
    </div>
    @endif
</div>