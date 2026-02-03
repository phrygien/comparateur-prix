<?php

use Livewire\Volt\Component;
use App\Models\Product;

new class extends Component {
    public string $name;
    public string $id;
    public string $price;
    public $products;

    public function mount($name, $id, $price): void
    {
        $this->name = $name;
        $this->id = $id;
        $this->price = $price;
        
        // Recherche avec Typesense Scout
        $this->products = Product::search($this->name)->get();
    }
    
}; ?>

<div>
    <h2>Résultats de recherche pour : {{ $name }}</h2>
    
    @if($products->count() > 0)
        <div class="products-list">
            @foreach($products as $product)
                <div class="product-item">
                    <img src="{{ $product->image_url }}" alt="{{ $product->name }}">
                    <h3>{{ $product->vendor }} - {{ $product->name }}</h3>
                    <p>{{ $product->type }}</p>
                    <p>{{ $product->variation }}</p>
                    <p class="price">{{ $product->prix_ht }} {{ $product->currency }}</p>
                    <a href="{{ $product->url }}" target="_blank">Voir le produit</a>
                </div>
            @endforeach
        </div>
    @else
        <p>Aucun produit trouvé pour "{{ $name }}"</p>
    @endif
</div>