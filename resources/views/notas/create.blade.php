<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Emitir nota</h2>
    </x-slot>

    @php
        $inicial = [
            'tipo' => old('tomador_tipo', $modelo->tomador_tipo ?? 'pj'),
            'documento' => old('tomador_documento', $modelo->tomador_documento ?? ''),
            'cep' => old('tomador_cep', $modelo->tomador_cep ?? ''),
            'cidade' => old('tomador_cidade', $modelo->tomador_cidade ?? ''),
            'uf' => old('tomador_uf', $modelo->tomador_uf ?? ''),
            'ibge' => old('tomador_ibge', $modelo->tomador_ibge ?? ''),
            'localNome' => old('local_prestacao_nome', $modelo->local_prestacao_nome ?? ''),
            'localIbge' => old('local_prestacao_ibge', $modelo->local_prestacao_ibge ?? ''),
        ];
        $ufs = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
        $campo = 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-emerald-500 focus:ring-emerald-500';
    @endphp

    <div class="py-8">
        <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
            @if($modelo)
                <p class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    Repetindo a nota de <strong>{{ $modelo->tomador_nome }}</strong>. Confira o valor e a descrição.
                </p>
            @endif

            <form method="POST" action="{{ route('notas.store') }}" x-data="formularioDaNota(@js($inicial))">
                @csrf

                <div class="mb-6 rounded-xl bg-white p-6 shadow-sm">
                    <h3 class="mb-1 text-sm font-semibold text-gray-800">Cliente</h3>
                    <p class="mb-5 text-xs text-gray-500">Quem consta como tomador na nota fiscal</p>

                    <div class="mb-4">
                        <div class="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-1">
                            <button type="button" @click="mudarTipo('pj')"
                                    :class="ehPj ? 'bg-white shadow-sm text-emerald-700' : 'text-gray-500'"
                                    class="rounded-md px-4 py-1.5 text-sm font-medium transition">Empresa</button>
                            <button type="button" @click="mudarTipo('pf')"
                                    :class="!ehPj ? 'bg-white shadow-sm text-emerald-700' : 'text-gray-500'"
                                    class="rounded-md px-4 py-1.5 text-sm font-medium transition">Pessoa</button>
                        </div>
                        <input type="hidden" name="tomador_tipo" :value="tipo">
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="tomador_documento" class="mb-1.5 block text-sm font-medium text-gray-700">
                                <span x-text="ehPj ? 'CNPJ' : 'CPF'"></span> <span class="text-red-500">*</span>
                            </label>
                            <input id="tomador_documento" name="tomador_documento" inputmode="numeric"
                                   x-model="documento" @input="documento = mascararDocumento(documento)"
                                   class="{{ $campo }}">
                            @error('tomador_documento') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="tomador_nome" class="mb-1.5 block text-sm font-medium text-gray-700">
                                <span x-text="ehPj ? 'Razão social' : 'Nome completo'"></span> <span class="text-red-500">*</span>
                            </label>
                            <input id="tomador_nome" name="tomador_nome" class="{{ $campo }}"
                                   value="{{ old('tomador_nome', $modelo->tomador_nome ?? '') }}">
                            @error('tomador_nome') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div class="sm:col-span-2">
                            <label for="tomador_email" class="mb-1.5 block text-sm font-medium text-gray-700">E-mail</label>
                            <input id="tomador_email" name="tomador_email" type="email" class="{{ $campo }}"
                                   value="{{ old('tomador_email', $modelo->tomador_email ?? '') }}">
                        </div>
                    </div>
                </div>

                <div class="mb-6 rounded-xl bg-white p-6 shadow-sm">
                    <h3 class="mb-1 text-sm font-semibold text-gray-800">Endereço do cliente</h3>
                    <p class="mb-5 text-xs text-gray-500">O CEP preenche o resto</p>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
                        <div class="sm:col-span-2">
                            <label for="tomador_cep" class="mb-1.5 block text-sm font-medium text-gray-700">CEP</label>
                            <input id="tomador_cep" name="tomador_cep" inputmode="numeric" class="{{ $campo }}"
                                   x-model="cep" @input="cep = mascararCep(cep)" @blur="buscarCep()">
                            <p class="mt-1 text-xs text-red-600" x-show="erroCep" x-text="erroCep" x-cloak></p>
                        </div>
                        <div class="sm:col-span-4">
                            <label for="tomador_logradouro" class="mb-1.5 block text-sm font-medium text-gray-700">Logradouro</label>
                            <input id="tomador_logradouro" name="tomador_logradouro" class="{{ $campo }}" x-model="logradouro">
                        </div>
                        <div class="sm:col-span-2">
                            <label for="tomador_numero" class="mb-1.5 block text-sm font-medium text-gray-700">Número</label>
                            <input id="tomador_numero" name="tomador_numero" class="{{ $campo }}"
                                   value="{{ old('tomador_numero', $modelo->tomador_numero ?? '') }}">
                        </div>
                        <div class="sm:col-span-4">
                            <label for="tomador_bairro" class="mb-1.5 block text-sm font-medium text-gray-700">Bairro</label>
                            <input id="tomador_bairro" name="tomador_bairro" class="{{ $campo }}" x-model="bairro">
                        </div>
                        <div class="sm:col-span-4">
                            <label for="tomador_cidade" class="mb-1.5 block text-sm font-medium text-gray-700">Cidade <span class="text-red-500">*</span></label>
                            <input id="tomador_cidade" name="tomador_cidade" class="{{ $campo }}"
                                   x-model="cidade" @input="sugerirLocal()">
                            @error('tomador_cidade') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label for="tomador_uf" class="mb-1.5 block text-sm font-medium text-gray-700">UF <span class="text-red-500">*</span></label>
                            <select id="tomador_uf" name="tomador_uf" class="{{ $campo }}" x-model="uf">
                                <option value=""></option>
                                @foreach($ufs as $sigla)
                                    <option value="{{ $sigla }}">{{ $sigla }}</option>
                                @endforeach
                            </select>
                            @error('tomador_uf') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <input type="hidden" name="tomador_ibge" :value="ibge">
                </div>

                <div class="mb-6 rounded-xl bg-white p-6 shadow-sm">
                    <h3 class="mb-1 text-sm font-semibold text-gray-800">O atendimento</h3>
                    <p class="mb-5 text-xs text-gray-500">Onde foi feito e quanto custou</p>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="local_prestacao_nome" class="mb-1.5 block text-sm font-medium text-gray-700">
                                Onde o atendimento foi feito <span class="text-red-500">*</span>
                            </label>
                            <input id="local_prestacao_nome" name="local_prestacao_nome" class="{{ $campo }}"
                                   x-model="localNome">
                            <p class="mt-1 text-xs text-gray-500">Já vem com a cidade do cliente. Mude se o atendimento foi em outro lugar.</p>
                            <input type="hidden" name="local_prestacao_ibge" :value="localIbge">
                            @error('local_prestacao_ibge') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="valor" class="mb-1.5 block text-sm font-medium text-gray-700">Valor <span class="text-red-500">*</span></label>
                            <input id="valor" name="valor" inputmode="decimal" class="{{ $campo }}"
                                   value="{{ old('valor', $modelo->valor ?? '') }}" placeholder="0,00">
                            @error('valor') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div class="sm:col-span-2">
                            <label for="descricao" class="mb-1.5 block text-sm font-medium text-gray-700">Descrição do serviço <span class="text-red-500">*</span></label>
                            <textarea id="descricao" name="descricao" rows="2" class="{{ $campo }}">{{ old('descricao', $modelo->descricao ?? $perfil['descricao_padrao']) }}</textarea>
                            @error('descricao') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3">
                    <a href="{{ route('notas.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Cancelar</a>
                    <button type="submit" class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700">
                        Emitir nota
                    </button>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('formularioDaNota', (inicial) => ({
                tipo: inicial.tipo,
                documento: inicial.documento,
                cep: inicial.cep,
                logradouro: @js(old('tomador_logradouro', $modelo->tomador_logradouro ?? '')),
                bairro: @js(old('tomador_bairro', $modelo->tomador_bairro ?? '')),
                cidade: inicial.cidade,
                uf: inicial.uf,
                ibge: inicial.ibge,
                localNome: inicial.localNome,
                localIbge: inicial.localIbge,
                erroCep: '',

                init() {
                    this.documento = this.mascararDocumento(this.documento);
                    this.cep = this.mascararCep(this.cep);
                },

                get ehPj() { return this.tipo === 'pj'; },

                mudarTipo(tipo) {
                    this.tipo = tipo;
                    this.documento = this.mascararDocumento(this.documento);
                },

                apenasDigitos(v) { return (v || '').toString().replace(/\D/g, ''); },

                mascararDocumento(v) {
                    const d = this.apenasDigitos(v);
                    return this.ehPj ? this.mascararCnpj(d.slice(0, 14)) : this.mascararCpf(d.slice(0, 11));
                },

                mascararCpf(d) {
                    let s = d.slice(0, 3);
                    if (d.length > 3) s += '.' + d.slice(3, 6);
                    if (d.length > 6) s += '.' + d.slice(6, 9);
                    if (d.length > 9) s += '-' + d.slice(9, 11);
                    return s;
                },

                mascararCnpj(d) {
                    let s = d.slice(0, 2);
                    if (d.length > 2) s += '.' + d.slice(2, 5);
                    if (d.length > 5) s += '.' + d.slice(5, 8);
                    if (d.length > 8) s += '/' + d.slice(8, 12);
                    if (d.length > 12) s += '-' + d.slice(12, 14);
                    return s;
                },

                mascararCep(v) {
                    const d = this.apenasDigitos(v).slice(0, 8);
                    return d.length > 5 ? d.slice(0, 5) + '-' + d.slice(5) : d;
                },

                // O local do atendimento acompanha a cidade do cliente enquanto
                // ninguém o alterar. Depois de alterado, para de seguir.
                sugerirLocal() {
                    if (!this.localNome || this.localNome === this.cidadeAnterior) {
                        this.localNome = this.cidade;
                        this.localIbge = this.ibge;
                    }
                    this.cidadeAnterior = this.cidade;
                },

                async buscarCep() {
                    const cep = this.apenasDigitos(this.cep);
                    this.erroCep = '';
                    if (cep.length !== 8) return;

                    try {
                        const r = await fetch(`/consultas/cep/${cep}`);
                        if (!r.ok) { this.erroCep = 'Não achamos esse CEP. Preencha na mão.'; return; }
                        const d = await r.json();
                        this.logradouro = d.logradouro || this.logradouro;
                        this.bairro = d.bairro || this.bairro;
                        this.cidade = d.cidade || '';
                        this.uf = d.uf || '';
                        this.ibge = d.ibge || '';
                        this.localNome = this.cidade;
                        this.localIbge = this.ibge;
                    } catch (e) {
                        this.erroCep = 'A busca de CEP não respondeu. Preencha na mão.';
                    }
                },
            }));
        });
    </script>
    @endpush
</x-app-layout>
