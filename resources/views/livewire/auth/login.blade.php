<?php

use Mary\Traits\Toast;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\{Layout, Title};

new
    #[Layout('components.layouts.guest')]
    #[Title('Login')]
    class extends Component {

    use Toast;

    #[Validate('required')] 
    public string $email = '';

    #[Validate('required')] 
    public string $password = '';

    public function login()
    {
        $this->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = \App\Models\User::where('email', $this->email)->first();

        if (!$user) {
            $this->error('Ces identifiants ne correspondent pas à nos enregistrements.');
            return;
        }

        // Check if password needs rehashing
        if (!str_starts_with($user->password, '$2y$')) {
            $user->password = Hash::make($this->password);
            $user->save();
        }

        if (Auth::attempt(['email' => $this->email, 'password' => $this->password])) {
            session()->regenerate();
            return $this->redirect('/boutique', navigate: true);
        }

        $this->error('Ces identifiants ne correspondent pas à nos enregistrements.');
    }

}; ?>

<div class="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100 flex items-center justify-center p-4">
    <div class="flex flex-col lg:flex-row w-full max-w-6xl rounded-2xl overflow-hidden shadow-2xl">
        
        <!-- Côté gauche - Formulaire -->
        <div class="flex-1 bg-white p-8 lg:p-12 flex items-center justify-center">
            <div class="w-full max-w-md">
                <!-- Logo amélioré -->
                <div class="flex justify-center mb-10">
                    <a href="/" class="group flex items-center gap-2">
                        <div class="relative">
                            <div class="w-10 h-10 bg-gradient-to-r from-amber-500 to-orange-500 rounded-xl flex items-center justify-center transform group-hover:rotate-12 transition-transform duration-300 shadow-lg">
                                <span class="text-white font-bold text-lg">P</span>
                            </div>
                            <div class="absolute -top-1 -right-1 w-4 h-4 bg-blue-500 rounded-full border-2 border-white"></div>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-2xl font-black text-gray-900 tracking-tight">PRIX</span>
                            <span class="text-2xl font-black text-amber-600 tracking-tight -mt-2">COSMA</span>
                        </div>
                    </a>
                </div>

                <!-- En-tête -->
                <div class="text-center mb-10">
                    <h1 class="text-3xl lg:text-4xl font-bold text-gray-900 mb-3">
                        Bon retour
                        <span class="block text-transparent bg-clip-text bg-gradient-to-r from-amber-600 to-orange-500">
                            parmi nous
                        </span>
                    </h1>
                    <p class="text-gray-500 text-sm">
                        Connectez-vous pour accéder à votre espace personnel
                    </p>
                </div>

                <!-- Formulaire -->
                <x-form wire:submit="login" class="space-y-6">
                    <div class="space-y-5">
                        <!-- Champ Email -->
                        <div class="group">
                            <label class="block text-sm font-medium text-gray-700 mb-2 ml-1">
                                Adresse email
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="h-5 w-5 text-gray-400 group-focus-within:text-amber-500 transition-colors" 
                                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                              d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"/>
                                    </svg>
                                </div>
                                <x-input 
                                    wire:model.live="email" 
                                    placeholder="votre@email.com" 
                                    class="pl-10 pr-4 py-3 w-full border border-gray-200 rounded-xl focus:ring-2 focus:ring-amber-500 focus:border-transparent transition-all duration-200 bg-gray-50 hover:bg-white"
                                    :label="false"
                                />
                            </div>
                        </div>

                        <!-- Champ Mot de passe -->
                        <div class="group">
                            <div class="flex justify-between items-center mb-2 ml-1">
                                <label class="block text-sm font-medium text-gray-700">
                                    Mot de passe
                                </label>
                                <a href="#" class="text-xs text-amber-600 hover:text-amber-700 font-medium transition-colors">
                                    Mot de passe oublié ?
                                </a>
                            </div>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="h-5 w-5 text-gray-400 group-focus-within:text-amber-500 transition-colors" 
                                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                              d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                    </svg>
                                </div>
                                <x-password 
                                    wire:model.lazy="password" 
                                    placeholder="••••••••" 
                                    class="pl-10 pr-12 py-3 w-full border border-gray-200 rounded-xl focus:ring-2 focus:ring-amber-500 focus:border-transparent transition-all duration-200 bg-gray-50 hover:bg-white"
                                    :label="false"
                                    clearable
                                />
                            </div>
                        </div>

                        <!-- Case à cocher "Se souvenir de moi" -->
                        <div class="flex items-center">
                            <input 
                                type="checkbox" 
                                id="remember"
                                class="h-4 w-4 text-amber-600 focus:ring-amber-500 border-gray-300 rounded"
                            >
                            <label for="remember" class="ml-2 text-sm text-gray-600">
                                Se souvenir de moi
                            </label>
                        </div>
                    </div>

                    <!-- Bouton de connexion -->
                    <x-slot:actions>
                        <x-button 
                            label="Se connecter" 
                            class="w-full py-3.5 px-4 bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600 text-white font-semibold rounded-xl shadow-lg hover:shadow-xl transform hover:-translate-y-0.5 transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none"
                            type="submit" 
                            spinner="login"
                            wire:loading.attr="disabled"
                        />
                    </x-slot:actions>
                </x-form>

                <!-- Séparateur -->
                <div class="my-8 flex items-center">
                    <div class="flex-1 border-t border-gray-200"></div>
                    <span class="px-4 text-sm text-gray-400">ou</span>
                    <div class="flex-1 border-t border-gray-200"></div>
                </div>

                <!-- Inscription -->
                <div class="text-center">
                    <p class="text-gray-600 text-sm">
                        Pas encore de compte ?
                        <a href="#" class="text-amber-600 hover:text-amber-700 font-semibold ml-1 transition-colors">
                            S'inscrire
                        </a>
                    </p>
                </div>
            </div>
        </div>

        <!-- Côté droit - Image et citation -->
        <div class="flex-1 hidden lg:block relative overflow-hidden">
            <!-- Overlay gradient -->
            <div class="absolute inset-0 bg-gradient-to-tr from-gray-900/90 via-gray-900/70 to-transparent z-10"></div>
            
            <!-- Image de fond -->
            <div 
                class="absolute inset-0 bg-cover bg-center bg-no-repeat transform hover:scale-105 transition-transform duration-700"
                style="background-image: url('https://images.pexels.com/photos/1888026/pexels-photo-1888026.jpeg?auto=compress&cs=tinysrgb&w=1600');"
            ></div>
            
            <!-- Contenu superposé -->
            <div class="relative z-20 h-full flex flex-col justify-end p-12">
                <!-- Citation améliorée -->
                <div class="mb-10">
                    <div class="inline-block p-1 bg-gradient-to-r from-amber-500/20 to-orange-500/20 rounded-lg mb-6">
                        <svg class="w-6 h-6 text-amber-400" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M14.017 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151c-2.432.917-3.995 3.638-3.995 5.849h4v10h-9.983zm-14.017 0v-7.391c0-5.704 3.748-9.57 9-10.609l.996 2.151c-2.433.917-3.996 3.638-3.996 5.849h3.983v10h-9.983z"/>
                        </svg>
                    </div>
                    <blockquote class="text-white text-2xl xl:text-3xl font-light leading-relaxed">
                        Toujours le meilleur prix,<br>
                        <span class="text-amber-300 font-medium">par rapport aux autres</span>
                    </blockquote>
                </div>

                <!-- Infos entreprise améliorées -->
                <div class="flex items-center gap-4">
                    <div class="relative">
                        <div class="w-16 h-16 rounded-full bg-gradient-to-r from-amber-500 to-orange-500 flex items-center justify-center shadow-xl">
                            <svg class="w-8 h-8 text-white" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M9 11l-3 3-1.5-1.5L3 16l3 3 5-5-2-2zm0-6l-3 3-1.5-1.5L3 10l3 3 5-5-2-2zm5 2h7v2h-7V7zm0 6h7v2h-7v-2zm0 6h7v2h-7v-2z"/>
                            </svg>
                        </div>
                        <div class="absolute -bottom-1 -right-1 w-6 h-6 bg-blue-500 rounded-full border-3 border-gray-900"></div>
                    </div>

                    <div class="flex-1">
                        <div class="text-white text-lg font-bold">PrixCosma</div>
                        <div class="text-gray-300 text-sm">Astucom - Communication - LTD</div>
                        <div class="mt-1 flex items-center gap-2">
                            <div class="flex">
                                <div class="w-1 h-1 bg-amber-400 rounded-full"></div>
                                <div class="w-1 h-1 bg-amber-400 rounded-full mx-0.5"></div>
                                <div class="w-1 h-1 bg-amber-400 rounded-full"></div>
                            </div>
                            <span class="text-xs text-gray-400">Service 24h/24</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>