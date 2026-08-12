<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Notas fiscais</h2>
            <a href="{{ route('notas.create') }}" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700">
                Emitir nota
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            @if(session('sucesso'))
                <p class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ session('sucesso') }}
                </p>
            @endif

            <div class="overflow-hidden rounded-xl bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-100 bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Data</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Cliente</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Valor</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Situação</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Documentos</th>
                                <th class="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @forelse($notas as $nota)
                                <tr class="transition hover:bg-gray-50">
                                    <td class="px-6 py-4 text-xs text-gray-500">{{ $nota->created_at->format('d/m/Y') }}</td>
                                    <td class="px-6 py-4">
                                        <p class="font-medium text-gray-800">{{ $nota->tomador_nome }}</p>
                                        <p class="text-xs text-gray-400">{{ $nota->local_prestacao_nome }}</p>
                                    </td>
                                    <td class="px-6 py-4 font-semibold text-gray-800">R$ {{ number_format($nota->valor, 2, ',', '.') }}</td>
                                    <td class="px-6 py-4">
                                        {{-- As quatro situações aparecem como são. Nota recusada não pode
                                             se passar por pendente: no TreinaEdu isso escondeu uma nota
                                             parada por um dia. --}}
                                        @php
                                            [$rotulo, $cor] = match ($nota->status) {
                                                'emitida' => ['Emitida', 'bg-emerald-50 text-emerald-700 border-emerald-200'],
                                                'erro' => ['Não emitida', 'bg-red-50 text-red-700 border-red-200'],
                                                'cancelada' => ['Cancelada', 'bg-gray-100 text-gray-600 border-gray-200'],
                                                default => ['Em emissão', 'bg-amber-50 text-amber-700 border-amber-200'],
                                            };
                                        @endphp
                                        <span class="inline-flex rounded-full border px-2 py-0.5 text-xs font-medium {{ $cor }}">{{ $rotulo }}</span>
                                        @if($nota->status === 'erro')
                                            <p class="mt-1 max-w-xs text-xs text-gray-500">{{ $nota->erro }}</p>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center justify-end gap-3">
                                            @if($nota->pdf_url)
                                                <a href="{{ $nota->pdf_url }}" target="_blank" rel="noopener" class="font-medium text-emerald-600 hover:text-emerald-700">PDF</a>
                                            @endif
                                            @if($nota->xml_url)
                                                <a href="{{ $nota->xml_url }}" target="_blank" rel="noopener" class="font-medium text-gray-500 hover:text-gray-700">XML</a>
                                            @endif
                                            @if(! $nota->pdf_url && ! $nota->xml_url)
                                                <span class="text-xs text-gray-300">&mdash;</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        @if($nota->origem === 'manual')
                                            <a href="{{ route('notas.create', ['repetir' => $nota->id]) }}"
                                               class="text-xs font-medium text-gray-500 hover:text-emerald-700">Repetir</a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-12 text-center">
                                        <p class="text-sm font-medium text-gray-500">Nenhuma nota ainda.</p>
                                        <p class="mt-1 text-xs text-gray-400">Emita a primeira pelo botão acima.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($notas->hasPages())
                    <div class="border-t border-gray-100 px-6 py-4">{{ $notas->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
