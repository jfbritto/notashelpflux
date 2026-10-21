@props(['active'])

@php
// O menu do celular abre dentro do topo verde, como o da tela larga.
$classes = ($active ?? false)
            ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-emerald-400 text-start text-base font-medium text-white bg-emerald-800 focus:outline-none focus:bg-emerald-800 transition duration-150 ease-in-out'
            : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-emerald-100 hover:text-white hover:bg-emerald-800 hover:border-emerald-600 focus:outline-none focus:text-white focus:bg-emerald-800 transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
