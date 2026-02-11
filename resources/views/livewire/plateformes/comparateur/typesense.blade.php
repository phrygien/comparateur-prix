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
            return [
                'percentage' => 0,
                'amount' => 0,
                'isCheaper' => false,
                'label' => ''
            ];
        }

        $ourPrice = floatval($this->price); // Notre prix
        $theirPrice = floatval($comparePrice); // Leur prix

        // Calculer la différence : (notre prix - leur prix) / leur prix * 100
        $difference = (($ourPrice - $theirPrice) / $theirPrice) * 100;
        $amountDiff = $ourPrice - $theirPrice;

        return [
            'percentage' => round(abs($difference), 2),
            'amount' => round(abs($amountDiff), 2),
            'isCheaper' => $ourPrice < $theirPrice, // Nous sommes moins cher qu'eux
            'label' => $ourPrice < $theirPrice ? 'Nous sommes moins chers' : 'Nous sommes plus chers'
        ];
    }

    public function search(): void
    {
        // Trigger la recherche
        $this->dispatch('search-updated');
    }

}; ?>

<div >
    <div class="w-full max-w-5xl mx-auto p-6">
    <livewire:plateformes.detail :id="$id" />

    @if($this->products->count() > 0)
        <div class="space-y-4">
            @foreach($this->products as $product)
                <div class="overflow-hidden bg-white ring-1 shadow-sm ring-gray-900/5 sm:rounded-xl">
                    <div class="relative flex justify-between gap-x-6 px-4 py-5 hover:bg-gray-50 sm:px-6">
                        <div class="flex min-w-0 gap-x-4">
                            {{-- Image --}}
                            @if($product->image_url)
                                <img class="size-12 flex-none rounded-full bg-gray-50 object-cover" 
                                     src="{{ $product->image_url }}" 
                                     alt="{{ $product->name }}"
                                     onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2248%22 height=%2248%22%3E%3Crect fill=%22%23e5e7eb%22 width=%2248%22 height=%2248%22/%3E%3C/svg%3E'">
                            @else
                                <div class="size-12 flex-none rounded-full bg-gray-200 flex items-center justify-center">
                                    <svg class="size-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                </div>
                            @endif

                            <div class="min-w-0 flex-auto">
                                {{-- Product Name --}}
                                <p class="text-sm/6 font-semibold text-gray-900">
                                    @if($product->url)
                                        <a href="{{ $product->url }}" target="_blank" rel="noopener noreferrer">
                                            <span class="absolute inset-x-0 -top-px bottom-0"></span>
                                            {{ $product->name ?? 'Sans nom' }}
                                        </a>
                                    @else
                                        {{ $product->name ?? 'Sans nom' }}
                                    @endif
                                </p>

                                {{-- Product Details --}}
                                <div class="mt-1 flex flex-col gap-1 text-xs/5 text-gray-500">
                                    @if($product->type)
                                        <span>{{ $product->type }}</span>
                                    @endif
                                    @if($product->variation)
                                        <span class="text-gray-400">{{ $product->variation }}</span>
                                    @endif
                                    <span class="font-mono">EAN: {{ $product->ean ?? 'N/A' }}</span>
                                    @if($product->vendor)
                                        <span>Vendeur: {{ $product->vendor }}</span>
                                    @endif
                                    @if($product->website)
                                        <div class="flex items-center gap-1">
                                            <svg class="size-3 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                                            </svg>
                                            <span class="text-indigo-600 font-medium">{{ $product->website->name ?? $product->website->url ?? 'Site' }}</span>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-x-4">
                            <div class="hidden sm:flex sm:flex-col sm:items-end">
                                {{-- Their Price --}}
                                <p class="text-sm/6 font-bold text-gray-900">
                                    {{ number_format($product->prix_ht ?? 0, 2) }} {{ $product->currency ?? '€' }}
                                </p>

                                {{-- Price Difference --}}
                                @if($this->price)
                                    @php
                                        $priceDiff = $this->calculatePriceDifference($product->prix_ht ?? 0);
                                    @endphp
                                    <div class="mt-1 flex items-center gap-x-1.5">
                                        @if($priceDiff['isCheaper'])
                                            <div class="flex-none rounded-full bg-emerald-500/20 p-1">
                                                <div class="size-1.5 rounded-full bg-emerald-500"></div>
                                            </div>
                                            <p class="text-xs/5 text-emerald-600 font-medium">{{ $priceDiff['label'] }}</p>
                                        @else
                                            <div class="flex-none rounded-full bg-red-500/20 p-1">
                                                <div class="size-1.5 rounded-full bg-red-500"></div>
                                            </div>
                                            <p class="text-xs/5 text-red-600 font-medium">{{ $priceDiff['label'] }}</p>
                                        @endif
                                    </div>
                                    <p class="text-xs/5 {{ $priceDiff['isCheaper'] ? 'text-emerald-600' : 'text-red-600' }} font-bold">
                                        {{ $priceDiff['percentage'] }}% ({{ number_format($priceDiff['amount'], 2) }} €)
                                    </p>
                                @endif
                            </div>

                            {{-- Chevron Icon --}}
                            @if($product->url)
                                <svg class="size-5 flex-none text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                                </svg>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-6 text-sm text-gray-600">
            <strong>{{ $this->products->count() }}</strong> résultat(s) trouvé(s)
        </div>
    @else
        <div class="bg-white shadow rounded-lg py-12 text-center">
            <svg class="mx-auto size-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <h3 class="mt-2 text-sm font-semibold text-gray-900">Aucun résultat</h3>
            <p class="mt-1 text-sm text-gray-500">
                {{ $searchEan ? 'Aucun produit trouvé pour cet EAN.' : 'Entrez un code EAN pour rechercher.' }}
            </p>
        </div>
    @endif
    </div>
</div>