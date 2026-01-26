<?php

use Livewire\Volt\Component;

new class extends Component {
    public $productText = "Armani - My Way Sunny Vanilla - Eau de Parfum Vaporisateur 90 ml";
    
    public $vendor = '';
    public $name = '';
    public $variation = '';
    public $type = '';
    
    public function mount()
    {
        $this->parseProductInfo();
    }
    
    public function parseProductInfo()
    {
        // Diviser le texte en parties
        $parts = explode(' - ', $this->productText);
        
        if (count($parts) >= 3) {
            $this->vendor = $parts[0]; // Armani
            $this->name = $parts[1]; // My Way Sunny Vanilla
            
            // Analyser la dernière partie pour type et variation
            $lastPart = $parts[2];
            
            // Chercher la taille/variation (comme 90 ml)
            if (preg_match('/(\d+\s*ml)/i', $lastPart, $matches)) {
                $this->variation = $matches[1];
                // Enlever la variation pour obtenir le type
                $this->type = trim(str_replace($this->variation, '', $lastPart));
            } else {
                $this->type = $lastPart;
            }
        }
    }
}; ?>

<div>
    <div class="p-4">
        <h3 class="font-bold mb-2">Informations du produit:</h3>
        <p><strong>Vendor:</strong> {{ $vendor }}</p>
        <p><strong>Name:</strong> {{ $name }}</p>
        <p><strong>Variation:</strong> {{ $variation }}</p>
        <p><strong>Type:</strong> {{ $type }}</p>
        
        <div class="mt-4 p-2 bg-gray-100">
            <p class="text-sm">Texte original: "{{ $productText }}"</p>
        </div>
    </div>
</div>