<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Notas fiscais</h2>
            <a href="{{ route('notas.create') }}" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700">
                Emitir nota
            </a>
        </div>
    </x-slot>

    @php
        // Como cada origem aparece na tela. "Manual" é quem emitiu aqui;
        // as demais chegaram pela API do SaaS correspondente.
        $origens = [
            'manual' => ['Manual', 'bg-gray-100 text-gray-600 border-gray-200'],
            'treinaedu' => ['API · TreinaEdu', 'bg-indigo-50 text-indigo-700 border-indigo-200'],
            'helpdiet' => ['API · HelpDiet', 'bg-sky-50 text-sky-700 border-sky-200'],
        ];
        $ehAdmin = auth()->user()->ehAdmin();
    @endphp

    <div class="py-8" x-data="{ cancelando: null, clienteDaNota: '' }">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            @if(session('sucesso'))
                <p class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ session('sucesso') }}
                </p>
            @endif
            @if(session('erro'))
                <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    {{ session('erro') }}
                </p>
            @endif
            @error('motivo')
                <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</p>
            @enderror

            {{-- Filtros de leitura: separar por tipo de serviço e, para o
                 admin, por onde a nota nasceu. Selects enviam sozinhos. --}}
            <form method="GET" action="{{ route('notas.index') }}" class="mb-4 flex flex-wrap items-center gap-3">
                <select name="perfil" onchange="this.form.submit()"
                        class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="">Todos os serviços</option>
                    @foreach($perfis as $chave => $dados)
                        <option value="{{ $chave }}" @selected(request('perfil') === $chave)>{{ $dados['rotulo'] }}</option>
                    @endforeach
                </select>

                @if($ehAdmin)
                    <select name="origem" onchange="this.form.submit()"
                            class="rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <option value="">Todas as origens</option>
                        @foreach($origens as $chave => [$rotuloOrigem])
                            <option value="{{ $chave }}" @selected(request('origem') === $chave)>{{ $rotuloOrigem }}</option>
                        @endforeach
                    </select>
                @endif

                @if(request()->hasAny(['perfil', 'origem']))
                    <a href="{{ route('notas.index') }}" class="text-xs text-gray-500 hover:text-gray-700">Limpar filtros</a>
                @endif
            </form>

            <div class="overflow-hidden rounded-xl bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-100 bg-gray-50">
                            <tr>
                                <th class="whitespace-nowrap px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Data</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Cliente</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Serviço</th>
                                <th class="whitespace-nowrap px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Valor</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Situação</th>
                                <th class="whitespace-nowrap px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Documentos</th>
                                <th class="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @forelse($notas as $nota)
                                <tr class="transition hover:bg-gray-50">
                                    <td class="whitespace-nowrap px-6 py-4 text-xs text-gray-500">{{ $nota->created_at->format('d/m/Y') }}</td>
                                    <td class="px-6 py-4">
                                        <p class="font-medium text-gray-800">{{ $nota->tomador_nome }}</p>
                                        <p class="text-xs text-gray-400">{{ $nota->local_prestacao_nome }}</p>
                                    </td>
                                    <td class="px-6 py-4">
                                        <p class="text-xs font-medium text-gray-700">{{ $perfis[$nota->perfil]['rotulo'] ?? ucfirst($nota->perfil) }}</p>
                                        @php
                                            [$rotuloOrigem, $corOrigem] = $origens[$nota->origem] ?? [ucfirst($nota->origem), 'bg-gray-100 text-gray-600 border-gray-200'];
                                            // Quem emitiu: só existe pra nota manual (a de API não tem um
                                            // usuário por trás, o próprio selo de origem já diz que foi
                                            // automático). Primeiro nome só, pra não estourar o selo.
                                            if ($nota->origem === 'manual' && $nota->autora) {
                                                $rotuloOrigem .= ' · '.explode(' ', $nota->autora->name)[0];
                                            }
                                        @endphp
                                        <span class="mt-1 inline-flex whitespace-nowrap rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $corOrigem }}">{{ $rotuloOrigem }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 font-semibold text-gray-800">R$ {{ number_format($nota->valor, 2, ',', '.') }}</td>
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
                                        <span class="inline-flex whitespace-nowrap rounded-full border px-2 py-0.5 text-xs font-medium {{ $cor }}">{{ $rotulo }}</span>
                                        {{-- Cancelamento pedido é ASSÍNCRONO: a nota continua "Emitida" (o
                                             documento ainda vale) até o emissor confirmar. Sem este aviso,
                                             clicar em Cancelar pareceria não ter feito nada. --}}
                                        @if($nota->status === 'emitida' && $nota->cancelamento_solicitado_em)
                                            <span class="mt-1 block max-w-xs text-xs font-medium text-amber-600">Cancelamento pedido, aguardando confirmação</span>
                                        @endif
                                        @if($nota->status === 'erro')
                                            <p class="mt-1 max-w-sm text-xs text-gray-500">{{ $nota->erro }}</p>
                                        @elseif($nota->status === 'cancelada' && $nota->motivo_cancelamento)
                                            <p class="mt-1 max-w-sm text-xs text-gray-400">{{ $nota->motivo_cancelamento }}</p>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4">
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
                                    <td class="whitespace-nowrap px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-3">
                                            @if($nota->origem === 'manual')
                                                <a href="{{ route('notas.create', ['repetir' => $nota->id]) }}"
                                                   class="text-xs font-medium text-gray-500 hover:text-emerald-700">Repetir</a>
                                            @endif
                                            {{-- Sem isso, quem cancela ou emite fica sem saber que a
                                                 confirmação leva minutos: a reconciliação só roda a cada
                                                 5 min, e o cron não avisa ninguém. --}}
                                            @if(($nota->status === 'processando' || ($nota->status === 'emitida' && $nota->cancelamento_solicitado_em)) && ($ehAdmin || $nota->origem === 'manual'))
                                                <form method="POST" action="{{ route('notas.verificar', $nota) }}">
                                                    @csrf
                                                    <button type="submit" class="text-xs font-medium text-gray-500 hover:text-emerald-700">Verificar agora</button>
                                                </form>
                                            @endif
                                            @if($nota->status === 'emitida' && ! $nota->cancelamento_solicitado_em && ($ehAdmin || $nota->origem === 'manual'))
                                                <button type="button"
                                                        @click="cancelando = {{ $nota->id }}; clienteDaNota = @js($nota->tomador_nome)"
                                                        class="text-xs font-medium text-red-400 hover:text-red-600">Cancelar</button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-6 py-12 text-center">
                                        <p class="text-sm font-medium text-gray-500">
                                            {{ request()->hasAny(['perfil', 'origem']) ? 'Nenhuma nota com esses filtros.' : 'Nenhuma nota ainda.' }}
                                        </p>
                                        @unless(request()->hasAny(['perfil', 'origem']))
                                            <p class="mt-1 text-xs text-gray-400">Emita a primeira pelo botão acima.</p>
                                        @endunless
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

        {{-- Cancelamento: pede a justificativa, porque no padrão nacional o
             cancelamento é um evento fiscal com motivo, e sai no documento. --}}
        <div x-show="cancelando !== null" x-cloak
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
             @keydown.escape.window="cancelando = null">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl" @click.outside="cancelando = null">
                <h3 class="text-sm font-bold text-gray-800">Cancelar a nota de <span x-text="clienteDaNota"></span></h3>
                <p class="mt-1 text-xs text-gray-500">
                    O cancelamento é um evento fiscal registrado na prefeitura, com prazo do município.
                    Explique o motivo: ele fica no documento.
                </p>

                <form method="POST" :action="`{{ url('/notas') }}/${cancelando}/cancelar`" class="mt-4">
                    @csrf
                    <label for="motivo" class="mb-1.5 block text-xs font-medium text-gray-700">Motivo do cancelamento</label>
                    <textarea id="motivo" name="motivo" rows="3" required minlength="15" maxlength="255"
                              placeholder="Ex.: valor lançado errado, nota emitida em duplicidade"
                              class="w-full rounded-lg border-gray-300 text-sm focus:border-red-400 focus:ring-red-400"></textarea>

                    <div class="mt-4 flex items-center justify-end gap-3">
                        <button type="button" @click="cancelando = null" class="text-sm text-gray-500 hover:text-gray-700">Voltar</button>
                        <button type="submit" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-700">
                            Cancelar a nota
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
