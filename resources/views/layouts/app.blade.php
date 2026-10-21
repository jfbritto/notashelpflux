<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        {{-- A plataforma é clara, e só. Sem isto o navegador escurece a
             página por conta própria quando o sistema está em modo escuro, e o
             resultado é um cinza lavado que não é o desenho de ninguém. --}}
        <meta name="color-scheme" content="light">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen bg-[#f1f5f2]">
            {{-- O topo verde, da navegação ao título da tela, termina na borda
                 serrilhada de um recibo: é a identidade da plataforma. --}}
            <div class="borda-de-recibo bg-emerald-900">
                @include('layouts.navigation')

                @isset($header)
                    <header>
                        <div class="mx-auto max-w-7xl px-4 pb-8 pt-6 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </header>
                @endisset
            </div>

            <!-- Page Content -->
            <main>
                {{ $slot }}
            </main>
        </div>
        @stack('scripts')
    </body>
</html>
