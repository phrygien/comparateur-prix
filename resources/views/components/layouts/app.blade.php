<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title . ' - ' . config('app.name') : config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Andika:ital,wght@0,400;0,700;1,400;1,700&display=swap"
        rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen font-sans antialiased bg-base-200">

    {{-- NAVBAR mobile only --}}
    <x-nav sticky class="lg:hidden">
        <x-slot:brand>
            <x-app-brand />
        </x-slot:brand>
        <x-slot:actions>
            <label for="main-drawer" class="lg:hidden me-3">
                <x-icon name="o-bars-3" class="cursor-pointer" />
            </label>
        </x-slot:actions>
    </x-nav>

    {{-- MAIN --}}
    <x-main full-width>
        {{-- SIDEBAR --}}
        <x-slot:sidebar drawer="main-drawer" class="bg-indigo-600">
            <div class="flex grow flex-col gap-y-5 overflow-y-auto px-6">
                {{-- BRAND --}}
                <div class="flex h-16 shrink-0 items-center">
                    <img class="h-8 w-auto" src="https://tailwindui.com/plus-assets/img/logos/mark.svg?color=white"
                        alt="{{ config('app.name') }}">
                </div>

                <nav class="flex flex-1 flex-col">
                    <ul role="list" class="flex flex-1 flex-col gap-y-7">
                        <li>
                            <ul role="list" class="-mx-2 space-y-1">
                                <li>
                                    <a href="/home"
                                        class="group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold {{ request()->is('home') ? 'bg-indigo-700 text-white' : 'text-indigo-200 hover:bg-indigo-700 hover:text-white' }}">
                                        <svg class="size-6 shrink-0 {{ request()->is('home') ? 'text-white' : 'text-indigo-200 group-hover:text-white' }}"
                                            fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                                        </svg>
                                        Accueil
                                    </a>
                                </li>
                                <li>
                                    <a href="/boutique"
                                        class="group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold {{ request()->is('boutique*') ? 'bg-indigo-700 text-white' : 'text-indigo-200 hover:bg-indigo-700 hover:text-white' }}">
                                        <svg class="size-6 shrink-0 {{ request()->is('boutique*') ? 'text-white' : 'text-indigo-200 group-hover:text-white' }}"
                                            fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349m-16.5 11.65V9.35m0 0a3.001 3.001 0 003.75-.615A2.993 2.993 0 009.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.016a3.001 3.001 0 003.75.614m-16.5 0a3.004 3.004 0 01-.621-4.72L4.318 3.44A1.5 1.5 0 015.378 3h13.243a1.5 1.5 0 011.06.44l1.19 1.189a3 3 0 01-.621 4.72m-13.5 8.65h3.75a.75.75 0 00.75-.75V13.5a.75.75 0 00-.75-.75H6.75a.75.75 0 00-.75.75v3.75c0 .415.336.75.75.75z" />
                                        </svg>
                                        Boutiques
                                    </a>
                                </li>
                                <li>
                                    <a href="/top-product"
                                        class="group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold {{ request()->is('top-product*') ? 'bg-indigo-700 text-white' : 'text-indigo-200 hover:bg-indigo-700 hover:text-white' }}">
                                        <svg class="size-6 shrink-0 {{ request()->is('top-product*') ? 'text-white' : 'text-indigo-200 group-hover:text-white' }}"
                                            fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />
                                        </svg>
                                        Créer une liste personnalisée
                                    </a>
                                </li>
                                <li>
                                    <a href="/scraped_products"
                                        class="group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold {{ request()->is('scraped_products*') ? 'bg-indigo-700 text-white' : 'text-indigo-200 hover:bg-indigo-700 hover:text-white' }}">
                                        <svg class="size-6 shrink-0 {{ request()->is('scraped_products*') ? 'text-white' : 'text-indigo-200 group-hover:text-white' }}"
                                            fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                                        </svg>
                                        Parcourir les concurrents
                                    </a>
                                </li>
                            </ul>
                        </li>

                        {{-- User Profile at bottom --}}
                        @if($user = auth()->user())
                            <li class="-mx-6 mt-auto">
                                <div
                                    class="flex items-center gap-x-4 px-6 py-3 text-sm/6 font-semibold text-white hover:bg-indigo-700">
                                    <img class="size-8 rounded-full bg-indigo-700"
                                        src="https://ui-avatars.com/api/?name={{ urlencode($user->name) }}&color=fff&background=4f46e5"
                                        alt="{{ $user->name }}">
                                    <span class="flex-1">
                                        <span class="block">{{ $user->name }}</span>
                                        <span class="text-xs text-indigo-200">{{ $user->email }}</span>
                                    </span>
                                    <livewire:auth.logout />
                                </div>
                            </li>
                        @endif
                    </ul>
                </nav>
            </div>
        </x-slot:sidebar>

        {{-- The `$slot` goes here --}}
        <x-slot:content>
            {{ $slot }}
        </x-slot:content>
    </x-main>

    {{-- TOAST area --}}
    <x-toast />
</body>

</html>