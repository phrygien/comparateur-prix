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

        $basePrice = floatval($this->price);
        $otherPrice = floatval($comparePrice);

        $difference = (($basePrice - $otherPrice) / $otherPrice) * 100;
        $amountDiff = $basePrice - $otherPrice;

        return [
            'percentage' => round(abs($difference), 2),
            'amount' => round(abs($amountDiff), 2),
            'isCheaper' => $basePrice < $otherPrice,
            'label' => $basePrice < $otherPrice ? 'Moins cher' : 'Plus cher'
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

    <!-- Results Table -->
    @php
        $headers = [
            ['key' => 'image_url', 'label' => 'Image'],
            ['key' => 'name', 'label' => 'Produit'],
            ['key' => 'ean', 'label' => 'EAN'],
            ['key' => 'vendor', 'label' => 'Vendeur'],
            ['key' => 'website.name', 'label' => 'Site'],
            ['key' => 'prix_ht', 'label' => 'Prix'],
            ['key' => 'price_diff', 'label' => 'Différence'],
            ['key' => 'actions', 'label' => 'Actions'],
        ];
    @endphp

    @if($this->products->count() > 0)
        <x-table :headers="$headers" :rows="$this->products" striped>
            {{-- Image Column --}}
            @scope('cell_image_url', $product)
            @if($product->image_url)
                <img class="size-12 rounded object-cover" src="{{ $product->image_url }}" alt="{{ $product->name }}" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2248%22 height=%2248%22%3E%3Crect fill=%22%23e5e7eb%22 width=%2248%22 height=%2248%22/%3E%3C/svg%3E'">
            @else
                <div class="size-12 rounded bg-gray-200 flex items-center justify-center">
                    <svg class="size-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
            @endif
            @endscope

            {{-- Product Name Column --}}
            @scope('cell_name', $product)
            <div class="min-w-0">
                <p class="text-sm font-semibold text-gray-900 truncate">{{ $product->name ?? 'Sans nom' }}</p>
                @if($product->type)
                    <p class="text-xs text-gray-500 truncate">{{ $product->type }}</p>
                @endif
                @if($product->variation)
                    <p class="text-xs text-gray-400 truncate">{{ $product->variation }}</p>
                @endif
            </div>
            @endscope

            {{-- EAN Column --}}
            @scope('cell_ean', $product)
            <span class="text-sm font-mono">{{ $product->ean ?? 'N/A' }}</span>
            @endscope

            {{-- Vendor Column --}}
            @scope('cell_vendor', $product)
            <span class="text-sm">{{ $product->vendor ?? '-' }}</span>
            @endscope

            {{-- Website Column --}}
            @scope('cell_website.name', $product)
            @if($product->website)
                <div class="flex items-center gap-1">
                    <svg class="size-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                    </svg>
                    <span class="text-sm text-indigo-600 font-medium">{{ $product->website->name ?? $product->website->url ?? 'Site' }}</span>
                </div>
            @else
                <span class="text-sm text-gray-400">-</span>
            @endif
            @endscope

            {{-- Price Column --}}
            @scope('cell_prix_ht', $product)
            <span class="font-semibold text-gray-900">{{ number_format($product->prix_ht ?? 0, 2) }} {{ $product->currency ?? '€' }}</span>
            @endscope

            {{-- Price Difference Column --}}
            @scope('cell_price_diff', $product)
            @if($this->price)
                @php
                    $priceDiff = $this->calculatePriceDifference($product->prix_ht ?? 0);
                @endphp
                <div class="flex flex-col gap-1">
                    <div class="flex items-center gap-1">
                        @if($priceDiff['isCheaper'])
                            <svg class="size-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                            </svg>
                        @else
                            <svg class="size-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"/>
                            </svg>
                        @endif
                        <span class="text-xs font-medium {{ $priceDiff['isCheaper'] ? 'text-green-600' : 'text-red-600' }}">
                            {{ $priceDiff['label'] }}
                        </span>
                    </div>
                    <div class="flex flex-col text-xs">
                        <span class="font-bold {{ $priceDiff['isCheaper'] ? 'text-green-600' : 'text-red-600' }}">
                            {{ $priceDiff['percentage'] }}%
                        </span>
                        <span class="text-gray-600">
                            {{ number_format($priceDiff['amount'], 2) }} €
                        </span>
                    </div>
                </div>
            @else
                <span class="text-xs text-gray-400">-</span>
            @endif
            @endscope

            {{-- Actions Column --}}
            @scope('cell_actions', $product)
            @if($product->url)
                <x-button
                    icon="o-eye"
                    link="{{ $product->url }}"
                    external
                    class="btn-sm btn-ghost"
                    tooltip="Voir le produit"
                />
            @else
                <span class="text-xs text-gray-400">N/A</span>
            @endif
            @endscope
        </x-table>

        <div class="mt-4 text-sm text-gray-600">
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