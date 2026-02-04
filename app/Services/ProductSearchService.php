<?php

namespace App\Services;

use OpenAI\Laravel\Facades\OpenAI;

class ProductSearchService
{
    public function extractProductInfo(string $searchTerm): array
    {
        $prompt = "Extrais les informations suivantes du nom de produit ci-dessous.

Règles:
- vendor: la marque du produit (généralement le premier mot)
- name: le nom du produit (sans la marque, sans les détails comme le volume)
- Si tu ne trouves pas le vendor, retourne null
- Si tu ne trouves pas le name, utilise tout le texte

Produit: {$searchTerm}

Réponds UNIQUEMENT en JSON avec cette structure exacte:
{
    \"vendor\": \"...\",
    \"name\": \"...\"
}";

        try {
            $result = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => 'Tu es un assistant qui extrait les informations de produits cosmétiques. Réponds uniquement en JSON valide.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.1,
                'response_format' => ['type' => 'json_object'],
            ]);

            $content = $result->choices[0]->message->content;
            $data = json_decode($content, true);

            return [
                'vendor' => $data['vendor'] ?? null,
                'name' => $data['name'] ?? $searchTerm,
            ];
        } catch (\Exception $e) {
            // En cas d'erreur, retourner le terme de recherche tel quel
            return [
                'vendor' => null,
                'name' => $searchTerm,
            ];
        }
    }
}
