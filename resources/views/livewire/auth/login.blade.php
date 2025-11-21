<?php

use Mary\Traits\Toast;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
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

        if (Auth::attempt(['email' => $this->email, 'password' => $this->password])) {
            session()->regenerate();
            return redirect()->intended('/dashboard');
        }

        $this->error('Ces identifiants ne correspondent pas à nos enregistrements.');
    }

}; ?>

<div class="flex min-h-full flex-col justify-center py-12 sm:px-6 lg:px-8">
  <div class="sm:mx-auto sm:w-full sm:max-w-md">
    <img class="mx-auto h-10 w-auto" src="https://tailwindui.com/plus-assets/img/logos/mark.svg?color=indigo&shade=600" alt="Your Company">
    <h2 class="mt-6 text-center text-2xl/9 font-bold tracking-tight text-gray-900">cosma Compare </h2>
  </div>

  <div class="mt-10 sm:mx-auto sm:w-full sm:max-w-[480px]">
    <div class="bg-white px-6 py-12 shadow-sm sm:rounded-lg sm:px-12">
      <form class="space-y-6" wire:submit="login" method="POST">
        <div>
            <x-input label="Email" wire:model.live="email" placeholder="" icon="o-user" />
        </div>

        <div>
            <x-password label="Mot de passe" wire:model.live="password" clearable />
        </div>

        <div>
            <x-button label="{{ __('Connexion') }}" class="btn-primary w-full" type="submit" spinner="login" />
        </div>
      </form>

    </div>
  </div>
</div>
