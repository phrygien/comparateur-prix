<?php

use Livewire\Volt\Component;
use App\Models\Product;
use Livewire\Attributes\Computed;

new class extends Component {
    public string $ean = '';
    public string $price = '';
    public string $id = '';
    public string $searchEan = '';

    public function mount($ean = '', $price = '', $id = ''): void
    {
        $this->ean = $ean;
        $this->price = $price;
        $this->id = $id;
        $this->searchEan = $ean;
    }

    #[Computed]
    public function products()
    {
        if (empty($this->searchEan)) {
            return collect([]);
        }

        // Recherche directe par EAN avec Eloquent
        return Product::with('website')
            ->where('ean', $this->searchEan)
            ->get();
    }

    public function calculatePriceDifference($comparePrice): array
    {
        if (empty($this->price) || empty($comparePrice)) {
            return ['percentage' => 0, 'isCheaper' => false];
        }

        $basePrice = floatval($this->price);
        $otherPrice = floatval($comparePrice);

        $difference = (($otherPrice - $basePrice) / $basePrice) * 100;

        return [
            'percentage' => round($difference, 2),
            'isCheaper' => $otherPrice < $basePrice
        ];
    }

    public function search(): void
    {
        // Trigger la recherche
        $this->dispatch('search-updated');
    }

}; ?>

<div class="w-full max-w-4xl mx-auto p-6">
    <!-- Search Input -->
    <div class="mb-6">
        <label for="ean-search" class="block text-sm font-medium text-gray-700 mb-2">
            Rechercher par EAN
        </label>
        <div class="flex gap-2">
            <input
                type="text"
                id="ean-search"
                wire:model.defer="searchEan"
                placeholder="Entrez le code EAN..."
                class="flex-1 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
            >
            <button
                wire:click="search"
                class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
            >
                Rechercher
            </button>
        </div>
    </div>

    <!-- Current Product Info -->
    @if($ean && $price)
        <div class="mb-6 p-4 bg-blue-50 rounded-lg border border-blue-200">
            <h3 class="text-sm font-semibold text-blue-900 mb-2">Produit de référence</h3>
            <div class="text-sm text-blue-700">
                <p><span class="font-medium">EAN:</span> {{ $ean }}</p>
                <p><span class="font-medium">Prix:</span> {{ number_format($price, 2) }} €</p>
                @if($id)
                    <p><span class="font-medium">ID:</span> {{ $id }}</p>
                @endif
            </div>
        </div>
    @endif

    <!-- Results List -->
    <div class="bg-white shadow rounded-lg">
        <ul role="list" class="divide-y divide-gray-100">
            @forelse($this->products as $product)
                @php
                    $priceDiff = $this->calculatePriceDifference($product->prix_ht ?? 0);
                @endphp
                <li class="flex items-center justify-between gap-x-6 py-5 px-4 hover:bg-gray-50">
                    <div class="flex min-w-0 gap-x-4 flex-1">
                        @if($product->image_url)
                            <img class="size-12 flex-none rounded bg-gray-50 object-cover" src="{{ $product->image_url }}" alt="{{ $product->name ?? 'Product' }}" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="size-12 flex-none rounded bg-gray-200 items-center justify-center hidden">
                                <svg class="size-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                            </div>
                        @else
                            <div class="size-12 flex-none rounded bg-gray-200 flex items-center justify-center">
                                <svg class="size-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                            </div>
                        @endif
                        <div class="min-w-0 flex-auto">
                            <p class="text-sm/6 font-semibold text-gray-900">{{ $product->name ?? 'Sans nom' }}</p>
                            <p class="mt-1 text-xs/5 text-gray-500">EAN: {{ $product->ean ?? 'N/A' }}</p>
                            @if($product->vendor)
                                <p class="mt-1 text-xs/5 text-gray-500">Vendeur: {{ $product->vendor }}</p>
                            @endif
                            @if($product->website)
                                <p class="mt-1 text-xs/5 text-indigo-600 font-medium">
                                <span class="inline-flex items-center gap-1">
                                    <svg class="size-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                                    </svg>
                                    {{ $product->website->name ?? $product->website->url ?? 'Site' }}
                                </span>
                                </p>
                            @endif
                        </div>
                    </div>

                    <div class="flex items-center gap-x-4">
                        <!-- Price Column -->
                        <div class="text-right">
                            <p class="text-sm font-semibold text-gray-900">
                                {{ number_format($product->prix_ht ?? 0, 2) }} {{ $product->currency ?? '€' }}
                            </p>
                            @if($price)
                                <p class="text-xs font-medium mt-1 {{ $priceDiff['isCheaper'] ? 'text-green-600' : 'text-red-600' }}">
                                    {{ $priceDiff['isCheaper'] ? '↓' : '↑' }}
                                    {{ abs($priceDiff['percentage']) }}%
                                </p>
                            @endif
                        </div>

                        <!-- View Button -->
                        @if($product->url)
                            <a
                                href="{{ $product->url }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-gray-900 ring-1 shadow-xs ring-gray-300 ring-inset hover:bg-gray-50"
                            >
                                Voir
                            </a>
                        @else
                            <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-400 ring-1 shadow-xs ring-gray-200 ring-inset cursor-not-allowed">
                            N/A
                        </span>
                        @endif
                    </div>
                </li>
            @empty
                <li class="py-12 text-center">
                    <svg class="mx-auto size-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <h3 class="mt-2 text-sm font-semibold text-gray-900">Aucun résultat</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ $searchEan ? 'Aucun produit trouvé pour cet EAN.' : 'Entrez un code EAN pour rechercher.' }}
                    </p>
                </li>
            @endforelse
        </ul>

        @if($this->products->count() > 0)
            <div class="px-4 py-3 border-t border-gray-100">
                <a
                    href="#"
                    class="flex w-full items-center justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 ring-1 shadow-xs ring-gray-300 ring-inset hover:bg-gray-50 focus-visible:outline-offset-0"
                >
                    Voir tous ({{ $this->products->count() }} résultats)
                </a>
            </div>
        @endif
    </div>
</div>
