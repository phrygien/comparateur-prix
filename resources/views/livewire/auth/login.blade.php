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

<div class="flex h-screen w-screen">
    <div class="flex-1 flex justify-center items-center bg-white">
        <div class="w-96 max-w-full space-y-6 px-6">
            <!-- Logo -->
            <div class="flex justify-center opacity-50">
                <a href="/" class="group flex items-center gap-3">
                    <span class="text-xl font-bold text-zinc-800">PRIX</span> <span class="text-xl font-bold text-amber-800">COSMA</span>
                </a>
            </div>

            <h2 class="text-center text-2xl font-bold text-gray-900">Bienvenue à nouveau</h2>

            <x-form wire:submit="login">
                <x-input label="Email" wire:model.live="email" placeholder="" icon="o-user" hint="Votre adresse email" />

                <x-password label="Mot de passe" wire:model.lazy="password" placeholder="" clearable hint="Votre mot de passe" />

                <x-slot:actions>
                    <x-button 
                        label="Connexion" 
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md text-sm disabled:opacity-50 disabled:cursor-not-allowed" 
                        type="submit" 
                        spinner="login"
                        wire:loading.attr="disabled" />
                </x-slot:actions>
            </x-form>

        </div>
    </div>

    <div class="flex-1 hidden lg:flex">
        <div class="relative h-full w-full bg-zinc-900 text-white flex flex-col justify-end items-start p-16"
             style="background-image: url('https://images.pexels.com/photos/7111496/pexels-photo-7111496.jpeg'); background-size: cover; background-position: center;">

            <blockquote class="mb-6 italic font-light text-2xl xl:text-3xl">
                "Toujours le meilleur prix, par rapport aux autres"
            </blockquote>

            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-full bg-white flex items-center justify-center">
                    <svg class="w-8 h-8 text-zinc-800" fill="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M9 11l-3 3-1.5-1.5L3 16l3 3 5-5-2-2zm0-6l-3 3-1.5-1.5L3 10l3 3 5-5-2-2zm5 2h7v2h-7V7zm0 6h7v2h-7v-2zm0 6h7v2h-7v-2z"/>
                    </svg>
                </div>

                <div>
                    <div class="text-lg font-medium">PrixCosma</div>
                    <div class="text-sm text-zinc-300">Astucom - Communication - LTD</div>
                </div>
            </div>
        </div>
    </div>
</div>