@props(['grande' => false])

{{-- A marca: o símbolo da nota e o nome. Para fundo escuro, que é onde ela
     aparece (o topo das telas e o login). --}}
<span {{ $attributes->merge(['class' => 'inline-flex items-center whitespace-nowrap '.($grande ? 'gap-3' : 'gap-2.5')]) }}>
    <x-simbolo-da-nota @class(['shrink-0', 'h-12 w-12' => $grande, 'h-8 w-8' => ! $grande]) />
    <span class="flex flex-col leading-none">
        <span @class(['font-bold tracking-tight text-white', 'text-3xl' => $grande, 'text-lg' => ! $grande])>Notas</span>
        <span @class(['mt-1 font-semibold uppercase text-emerald-300', 'text-xs tracking-[0.25em]' => $grande, 'text-[10px] tracking-[0.2em]' => ! $grande])>HelpFlux</span>
    </span>
</span>
