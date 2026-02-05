<?php

namespace App\Services;

use OpenAI\Client;

class ProductSearchParser
{
    private Client $openai;

    public function __construct()
    {
        $this->openai = \OpenAI::client(env('OPENAI_API_KEY'));
    }

    /**
     * Extrait les critères de recherche d'un nom de produit
     *
     * @param string $productName
     * @return array{vendor: string|null, name: string|null, type: string|null, variation: string|null}
     */
    public function parseProductName(string $productName): array
    {
        $prompt = $this->buildPrompt($productName);

        try {
            $response = $this->openai->chat()->create([
                'model' => 'gpt-4-turbo-preview', // Utilisez 'gpt-4' ou 'gpt-4-turbo-preview'
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Tu es un expert en analyse de noms de produits cosmétiques et parfums. Tu extrais avec précision le vendor (marque), le nom du produit, le type de produit et les variations (contenance, etc.).'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.1,
                'response_format' => ['type' => 'json_object'],
            ]);

            $content = $response->choices[0]->message->content;
            $parsed = json_decode($content, true);

            return [
                'vendor' => $parsed['vendor'] ?? null,
                'name' => $parsed['name'] ?? null,
                'type' => $parsed['type'] ?? null,
                'variation' => $parsed['variation'] ?? null,
            ];

        } catch (\Exception $e) {
            \Log::error('Erreur lors du parsing du produit: ' . $e->getMessage());
            
            return [
                'vendor' => null,
                'name' => null,
                'type' => null,
                'variation' => null,
            ];
        }
    }

    /**
     * Construit le prompt pour OpenAI
     */
    private function buildPrompt(string $productName): string
    {
        return <<<PROMPT
Analyse le nom de produit suivant et extrais les informations en format JSON strict :

Nom du produit : "$productName"

Règles d'extraction :
1. **vendor** : La marque ou le fabricant (généralement le premier mot avant le tiret)
2. **name** : Le nom commercial du produit (entre les tirets, sans le type ni la variation)
3. **type** : Le type de produit (ex: "Eau de Parfum Vaporisateur", "Eau de Toilette", "Crème", etc.)
4. **variation** : La contenance ou variation (ex: "30ml", "50ml", "100ml", etc.)

Exemple :
Entrée : "Cacharel - Ella Ella Flora Azura - Eau de Parfum Vaporisateur 30ml"
Sortie : {
  "vendor": "Cacharel",
  "name": "Ella Ella Flora Azura",
  "type": "Eau de Parfum Vaporisateur",
  "variation": "30ml"
}

Retourne UNIQUEMENT le JSON sans aucun texte additionnel. Si une information n'est pas trouvée, utilise null.
PROMPT;
    }

    /**
     * Parse plusieurs produits en batch
     *
     * @param array $productNames
     * @return array
     */
    public function parseMultipleProducts(array $productNames): array
    {
        $results = [];
        
        foreach ($productNames as $productName) {
            $results[] = [
                'original' => $productName,
                'parsed' => $this->parseProductName($productName)
            ];
        }

        return $results;
    }
}