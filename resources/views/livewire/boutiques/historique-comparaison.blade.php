<?php

namespace App\Http\Livewire;

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;

new class extends Component {
    public $perPage = 15;
    public $page = 1;
    public $loading = false;
    public $hasMore = true;
    public $comparaisons = [];
    
    public function mount()
    {
        $this->loadData();
    }
    
    public function loadData()
    {
        if ($this->loading || !$this->hasMore) {
            return;
        }
        
        $this->loading = true;
        
        // Récupérer les données avec Query Builder
        $newComparaisons = DB::connection('mysql')
            ->table('list_product')
            ->select('*')
            ->orderBy('created_at', 'desc')
            ->skip(($this->page - 1) * $this->perPage)
            ->take($this->perPage)
            ->get();
            
        // Convertir en collection et fusionner
        $newComparaisons = collect($newComparaisons);
        $this->comparaisons = $this->comparaisons->concat($newComparaisons);
        
        // Vérifier s'il y a plus de données
        $totalItems = DB::connection('mysql')
            ->table('list_product')
            ->count();
            
        $this->hasMore = ($this->page * $this->perPage) < $totalItems;
        
        $this->page++;
        $this->loading = false;
    }
    
    public function loadMore()
    {
        $this->loadData();
    }
}; ?>

<div 
    x-data="{
        handleScroll() {
            const threshold = 100;
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
                    <th>ID</th>
                    <th>Libellé</th>
                    <th>Statut</th>
                    <th>Date de création</th>
                    <th>Dernière modification</th>
                </tr>
            </thead>
            <tbody>
                @foreach($comparaisons as $comparaison)
                <tr wire:key="comparaison-{{ $comparaison->id }}">
                    <td>{{ $comparaison->id }}</td>
                    <td>{{ $comparaison->libelle }}</td>
                    <td>
                        <span class="badge {{ $comparaison->status ? 'badge-success' : 'badge-error' }}">
                            {{ $comparaison->status ? 'Actif' : 'Inactif' }}
                        </span>
                    </td>
                    <td>
                        @if($comparaison->created_at)
                            {{ date('d/m/Y H:i', strtotime($comparaison->created_at)) }}
                        @else
                            -
                        @endif
                    </td>
                    <td>
                        @if($comparaison->updated_at)
                            {{ date('d/m/Y H:i', strtotime($comparaison->updated_at)) }}
                        @else
                            -
                        @endif
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

    <!-- Indicateur de chargement -->
    @if($loading)
    <div class="text-center py-6">
        <div class="inline-block animate-spin rounded-full h-10 w-10 border-t-2 border-b-2 border-blue-500"></div>
        <p class="mt-2 text-gray-600">Chargement des données...</p>
    </div>
    @endif

    <!-- Message de fin -->
    @if(!$hasMore && $comparaisons->isNotEmpty())
    <div class="alert alert-info shadow-lg">
        <div>
            <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current flex-shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>Toutes les comparaisons ont été chargées ({{ $comparaisons->count() }} éléments)</span>
        </div>
    </div>
    @endif
</div>