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

<div class="w-full max-w-7xl mx-auto p-6">
    <livewire:plateformes.detail :id="$id" />

    @if($this->products->count() > 0)
        <div class="space-y-4">
            @foreach($this->products as $product)
                <div class="overflow-hidden bg-white ring-1 shadow-sm ring-gray-900/5 sm:rounded-xl">
                    <div class="relative px-4 py-5 hover:bg-gray-50 sm:px-6">
                        {{-- Header with Image, Name, and Price --}}
                        <div class="flex items-start gap-x-4 mb-4">
                            {{-- Image --}}
                            @if($product->image_url)
                                <img class="size-16 flex-none rounded-lg bg-gray-50 object-cover" 
                                     src="{{ $product->image_url }}" 
                                     alt="{{ $product->name }}"
                                     onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2264%22 height=%2264%22%3E%3Crect fill=%22%23e5e7eb%22 width=%2264%22 height=%2264%22/%3E%3C/svg%3E'">
                            @else
                                <div class="size-16 flex-none rounded-lg bg-gray-200 flex items-center justify-center">
                                    <svg class="size-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                </div>
                            @endif

                            <div class="flex-1 min-w-0">
                                {{-- Product Name --}}
                                <p class="text-base font-semibold text-gray-900 mb-1">
                                    @if($product->url)
                                        <a href="{{ $product->url }}" target="_blank" rel="noopener noreferrer" class="hover:text-indigo-600">
                                            {{ $product->name ?? 'Sans nom' }}
                                            <svg class="inline size-4 text-gray-400 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                            </svg>
                                        </a>
                                    @else
                                        {{ $product->name ?? 'Sans nom' }}
                                    @endif
                                </p>

                                {{-- Website Badge --}}
                                @if($product->website)
                                    <div class="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-indigo-50 text-xs text-indigo-700 font-medium">
                                        <svg class="size-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                                        </svg>
                                        {{ $product->website->name ?? $product->website->url ?? 'Site' }}
                                    </div>
                                @endif
                            </div>

                            {{-- Price and Difference (Right Side) --}}
                            <div class="flex flex-col items-end gap-2">
                                <p class="text-lg font-bold text-gray-900">
                                    {{ number_format($product->prix_ht ?? 0, 2) }} {{ $product->currency ?? '€' }}
                                </p>

                                @if($this->price)
                                    @php
                                        $priceDiff = $this->calculatePriceDifference($product->prix_ht ?? 0);
                                    @endphp
                                    <div class="flex items-center gap-x-1.5">
                                        @if($priceDiff['isCheaper'])
                                            <div class="flex-none rounded-full bg-emerald-500/20 p-1">
                                                <div class="size-1.5 rounded-full bg-emerald-500"></div>
                                            </div>
                                            <p class="text-xs text-emerald-600 font-medium">{{ $priceDiff['label'] }}</p>
                                        @else
                                            <div class="flex-none rounded-full bg-red-500/20 p-1">
                                                <div class="size-1.5 rounded-full bg-red-500"></div>
                                            </div>
                                            <p class="text-xs text-red-600 font-medium">{{ $priceDiff['label'] }}</p>
                                        @endif
                                    </div>
                                    <p class="text-sm {{ $priceDiff['isCheaper'] ? 'text-emerald-600' : 'text-red-600' }} font-bold">
                                        {{ $priceDiff['percentage'] }}% ({{ number_format($priceDiff['amount'], 2) }} €)
                                    </p>
                                @endif
                            </div>
                        </div>

                        {{-- Product Details in Two Columns --}}
                        <div class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                            {{-- Left Column --}}
                            <div class="space-y-2">
                                @if($product->type)
                                    <div>
                                        <span class="text-gray-500 font-medium">Type:</span>
                                        <span class="text-gray-900 ml-2">{{ $product->type }}</span>
                                    </div>
                                @endif

                                @if($product->ean)
                                    <div>
                                        <span class="text-gray-500 font-medium">EAN:</span>
                                        <span class="text-gray-900 font-mono ml-2">{{ $product->ean }}</span>
                                    </div>
                                @endif

                                @if($product->vendor)
                                    <div>
                                        <span class="text-gray-500 font-medium">Vendeur:</span>
                                        <span class="text-gray-900 ml-2">{{ $product->vendor }}</span>
                                    </div>
                                @endif
                            </div>

                            {{-- Right Column --}}
                            <div class="space-y-2">
                                @if($product->variation)
                                    <div>
                                        <span class="text-gray-500 font-medium">Variation:</span>
                                        <span class="text-gray-900 ml-2">{{ $product->variation }}</span>
                                    </div>
                                @endif
                            </div>
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