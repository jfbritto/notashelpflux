<x-app-layout>
    <x-slot name="header">
        <h2 class="text-2xl font-semibold leading-tight text-white">Emitir nota</h2>
        <p class="mt-1 text-sm text-emerald-200">Escolha o serviço, o cliente e o valor. A tributação sai do tipo de serviço.</p>
    </x-slot>

    @php
        $inicial = [
            'perfil' => old('perfil', $modelo->perfil ?? 'nutricao'),
            // O que cada perfil sugere: o texto do serviço e onde ele foi
            // prestado. Atendimento acontece onde o cliente está;
            // desenvolvimento sai do estabelecimento da empresa.
            'perfis' => collect($perfis)->map(fn ($p) => [
                'rotulo' => $p['rotulo'],
                'descricao' => $p['descricao_padrao'],
                'localNoPrestador' => $p['local_prestacao_padrao'] === 'prestador',
            ]),
            'prestadorNome' => $prestador['nome_municipio'],
            'prestadorIbge' => $prestador['codigo_municipio'],
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

                <div class="mb-6 rounded-xl bg-white p-6 shadow-sm ring-1 ring-emerald-900/5">
                    <x-cabecalho-da-secao icone="servico" titulo="Tipo de serviço"
                                          subtitulo="Define a tributação da nota. Na dúvida, é o que você prestou." />

                    <div class="flex flex-wrap gap-3">
                        @foreach($perfis as $chave => $dados)
                            <button type="button" @click="mudarPerfil('{{ $chave }}')"
                                    :class="perfil === '{{ $chave }}' ? 'border-emerald-500 bg-emerald-50 text-emerald-800' : 'border-gray-200 text-gray-600 hover:border-gray-300'"
                                    class="rounded-lg border px-4 py-3 text-left text-sm font-medium transition">
                                {{ $dados['rotulo'] }}
                            </button>
                        @endforeach
                    </div>
                    <input type="hidden" name="perfil" :value="perfil">
                    @error('perfil') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="mb-6 rounded-xl bg-white p-6 shadow-sm ring-1 ring-emerald-900/5">
                    <x-cabecalho-da-secao icone="cliente" titulo="Cliente"
                                          subtitulo="Quem consta como tomador na nota fiscal. Com CNPJ, a Receita preenche o resto." />

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
                            {{-- Colado ou digitado, o CNPJ completo busca a empresa na
                                 Receita e preenche o cliente (ConsultaController::cnpj). --}}
                            <div class="relative">
                                <input id="tomador_documento" name="tomador_documento" inputmode="numeric"
                                       x-model="documento" @input="documento = mascararDocumento(documento); consultarCnpj()"
                                       @blur="consultarCnpj()"
                                       :placeholder="ehPj ? '00.000.000/0000-00' : '000.000.000-00'"
                                       :aria-busy="buscandoCnpj ? 'true' : 'false'"
                                       class="{{ $campo }} pr-9">
                                <svg x-show="buscandoCnpj" x-cloak class="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin text-emerald-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            </div>
                            <p class="mt-1 text-xs text-emerald-700" x-show="buscandoCnpj" x-cloak>Buscando a empresa na Receita Federal...</p>
                            <p class="mt-1 text-xs text-emerald-700" x-show="cnpjPreenchido && !buscandoCnpj" x-cloak>Preenchemos com os dados da Receita Federal. Confira antes de emitir.</p>
                            <p class="mt-1 text-xs text-amber-700" x-show="cnpjSituacao" x-cloak x-text="cnpjSituacao"></p>
                            <p class="mt-1 text-xs text-gray-500" x-show="cnpjAviso" x-cloak x-text="cnpjAviso"></p>
                            @error('tomador_documento') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="tomador_nome" class="mb-1.5 block text-sm font-medium text-gray-700">
                                <span x-text="ehPj ? 'Razão social' : 'Nome completo'"></span> <span class="text-red-500">*</span>
                            </label>
                            <input id="tomador_nome" name="tomador_nome" class="{{ $campo }}" x-model="nome"
                                   :placeholder="ehPj ? 'Como registrado na Receita' : 'Nome completo, como no documento'">
                            @error('tomador_nome') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div class="sm:col-span-2">
                            <label for="tomador_email" class="mb-1.5 block text-sm font-medium text-gray-700">E-mail</label>
                            <input id="tomador_email" name="tomador_email" type="email" class="{{ $campo }}" x-model="email"
                                   placeholder="Para onde a nota vai (opcional)">
                        </div>
                    </div>
                </div>

                <div class="mb-6 rounded-xl bg-white p-6 shadow-sm ring-1 ring-emerald-900/5">
                    <x-cabecalho-da-secao icone="endereco" titulo="Endereço do cliente" subtitulo="O CEP preenche o resto" />

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
                        <div class="sm:col-span-2">
                            <label for="tomador_cep" class="mb-1.5 block text-sm font-medium text-gray-700">CEP</label>
                            <input id="tomador_cep" name="tomador_cep" inputmode="numeric" class="{{ $campo }}" placeholder="00000-000"
                                   x-model="cep" @input="cep = mascararCep(cep)" @blur="buscarCep()">
                            <p class="mt-1 text-xs text-red-600" x-show="erroCep" x-text="erroCep" x-cloak></p>
                        </div>
                        <div class="sm:col-span-4">
                            <label for="tomador_logradouro" class="mb-1.5 block text-sm font-medium text-gray-700">Logradouro</label>
                            <input id="tomador_logradouro" name="tomador_logradouro" class="{{ $campo }}" x-model="logradouro" placeholder="Rua, avenida, praça">
                        </div>
                        <div class="sm:col-span-2">
                            <label for="tomador_numero" class="mb-1.5 block text-sm font-medium text-gray-700">Número</label>
                            <input id="tomador_numero" name="tomador_numero" class="{{ $campo }}" placeholder="123" x-model="numero">
                        </div>
                        <div class="sm:col-span-4">
                            <label for="tomador_bairro" class="mb-1.5 block text-sm font-medium text-gray-700">Bairro</label>
                            <input id="tomador_bairro" name="tomador_bairro" class="{{ $campo }}" x-model="bairro" placeholder="Centro">
                        </div>
                        <div class="sm:col-span-4">
                            <label for="tomador_cidade" class="mb-1.5 block text-sm font-medium text-gray-700">Cidade <span class="text-red-500">*</span></label>
                            <input id="tomador_cidade" name="tomador_cidade" class="{{ $campo }}" placeholder="Vitória"
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

                <div class="mb-6 rounded-xl bg-white p-6 shadow-sm ring-1 ring-emerald-900/5">
                    <x-cabecalho-da-secao icone="valor" titulo="O atendimento" subtitulo="Onde foi feito e quanto custou" />

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="local_prestacao_nome" class="mb-1.5 block text-sm font-medium text-gray-700">
                                Onde o atendimento foi feito <span class="text-red-500">*</span>
                            </label>
                            <input id="local_prestacao_nome" name="local_prestacao_nome" class="{{ $campo }}" placeholder="Cidade do atendimento"
                                   x-model="localNome">
                            <p class="mt-1 text-xs text-gray-500">Já vem com a cidade do cliente. Mude se o atendimento foi em outro lugar.</p>
                            <input type="hidden" name="local_prestacao_ibge" :value="localIbge">
                            @error('local_prestacao_ibge') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="valor" class="mb-1.5 block text-sm font-medium text-gray-700">Valor <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm font-medium text-gray-400">R$</span>
                                <input id="valor" name="valor" inputmode="numeric" class="{{ $campo }} pl-10"
                                       x-model="valor" @input="valor = mascararValor(valor)" placeholder="0,00">
                            </div>
                            @error('valor') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div class="sm:col-span-2">
                            <label for="descricao" class="mb-1.5 block text-sm font-medium text-gray-700">Descrição do serviço <span class="text-red-500">*</span></label>
                            <textarea id="descricao" name="descricao" rows="2" class="{{ $campo }}" x-model="descricao"
                                      placeholder="O que sai escrito na nota"></textarea>
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
                perfil: inicial.perfil,
                perfis: inicial.perfis,
                descricao: @js(old('descricao', $modelo->descricao ?? null)),
                tipo: inicial.tipo,
                documento: inicial.documento,
                nome: @js(old('tomador_nome', $modelo->tomador_nome ?? '')),
                email: @js(old('tomador_email', $modelo->tomador_email ?? '')),
                cep: inicial.cep,
                logradouro: @js(old('tomador_logradouro', $modelo->tomador_logradouro ?? '')),
                numero: @js(old('tomador_numero', $modelo->tomador_numero ?? '')),
                bairro: @js(old('tomador_bairro', $modelo->tomador_bairro ?? '')),
                cidade: inicial.cidade,
                uf: inicial.uf,
                ibge: inicial.ibge,
                localNome: inicial.localNome,
                localIbge: inicial.localIbge,
                valor: @js((string) old('valor', $modelo->valor ?? '')),
                erroCep: '',
                buscandoCnpj: false,
                cnpjPreenchido: false,
                cnpjSituacao: '',
                cnpjAviso: '',
                // O último CNPJ pedido, para o blur depois do colar não buscar
                // de novo; e de qual CNPJ são os dados que estão na tela.
                cnpjBuscado: '',
                cnpjDosDados: (inicial.documento || '').toString().replace(/\D/g, ''),

                init() {
                    this.documento = this.mascararDocumento(this.documento);
                    this.cep = this.mascararCep(this.cep);
                    this.valor = this.mascararValor(this.valor);

                    if (!this.descricao) {
                        this.descricao = this.perfis[this.perfil].descricao;
                    }
                    this.sugerirLocal();
                },

                /**
                 * Trocar o tipo troca a tributação da nota, e junto com ela o
                 * texto padrão e onde o serviço consta como prestado. O que a
                 * pessoa escreveu à mão não é sobrescrito.
                 */
                mudarPerfil(chave) {
                    const anterior = this.perfis[this.perfil].descricao;
                    this.perfil = chave;

                    if (!this.descricao || this.descricao === anterior) {
                        this.descricao = this.perfis[chave].descricao;
                    }

                    this.sugerirLocal(true);
                },

                get ehPj() { return this.tipo === 'pj'; },

                mudarTipo(tipo) {
                    this.tipo = tipo;
                    this.documento = this.mascararDocumento(this.documento);
                    this.cnpjPreenchido = false;
                    this.cnpjSituacao = '';
                    this.cnpjAviso = '';
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

                /**
                 * Reais como na maquininha: os dígitos entram pela direita e
                 * os dois últimos são os centavos ("150000" vira "1.500,00").
                 * Colar "1.500,00" ou "1500.00" dá o mesmo, porque só os
                 * dígitos contam. Dez dígitos é o teto da coluna. O servidor
                 * desfaz a máscara (EmitirNotaRequest).
                 */
                mascararValor(v) {
                    const d = this.apenasDigitos(v).replace(/^0+(?=\d)/, '').slice(0, 10);
                    if (!d) return '';
                    const comCentavos = d.padStart(3, '0');
                    const reais = comCentavos.slice(0, -2).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                    return `${reais},${comCentavos.slice(-2)}`;
                },

                /**
                 * Onde o serviço consta como prestado.
                 *
                 * Atendimento de nutrição acontece onde o cliente está;
                 * software sai do estabelecimento da empresa. A sugestão segue
                 * o tipo escolhido, e para de seguir assim que alguém escrever
                 * ali à mão, porque aí a pessoa sabe algo que o sistema não
                 * sabe (atendimento a domicílio, por exemplo).
                 */
                sugerirLocal(forcar = false) {
                    const noPrestador = this.perfis[this.perfil].localNoPrestador;
                    const nome = noPrestador ? inicial.prestadorNome : this.cidade;
                    const ibge = noPrestador ? inicial.prestadorIbge : this.ibge;

                    if (forcar || !this.localNome || this.localNome === this.ultimaSugestao) {
                        this.localNome = nome;
                        this.localIbge = ibge;
                    }

                    this.ultimaSugestao = nome;
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
                        // Passa pelo mesmo caminho da sugestão: numa nota de
                        // software, achar o CEP do cliente não muda onde o
                        // serviço foi prestado.
                        this.sugerirLocal();
                    } catch (e) {
                        this.erroCep = 'A busca de CEP não respondeu. Preencha na mão.';
                    }
                },

                /**
                 * Colou ou terminou de digitar o CNPJ: a Receita preenche o
                 * cliente.
                 *
                 * Trocar de CNPJ é trocar de cliente, e aí o que a Receita traz
                 * substitui o que havia. No mesmo CNPJ (repetir nota, por
                 * exemplo) só completa o que está vazio, para não passar por
                 * cima do que a pessoa corrigiu à mão. Falhou, a tela segue
                 * editável: a busca é conveniência, não dependência.
                 */
                async consultarCnpj() {
                    const cnpj = this.apenasDigitos(this.documento);
                    if (!this.ehPj || cnpj.length !== 14 || cnpj === this.cnpjBuscado) return;

                    const trocou = cnpj !== this.cnpjDosDados;
                    if (!trocou && this.nome.trim()) return;

                    this.cnpjBuscado = cnpj;
                    this.buscandoCnpj = true;
                    this.cnpjPreenchido = false;
                    this.cnpjSituacao = '';
                    this.cnpjAviso = '';

                    try {
                        const r = await fetch(`/consultas/cnpj/${cnpj}`, { headers: { Accept: 'application/json' } });
                        // O documento mudou enquanto esperava: a resposta é de
                        // outro cliente.
                        if (this.apenasDigitos(this.documento) !== cnpj) return;
                        if (r.status !== 200) { this.cnpjAviso = 'Não achamos esse CNPJ na Receita. Preencha na mão.'; return; }

                        const d = await r.json();
                        const por = (campo, valor) => {
                            if (valor && (trocou || !String(this[campo] || '').trim())) this[campo] = valor;
                        };

                        por('nome', d.nome);
                        por('email', d.email);
                        por('cep', this.mascararCep(d.cep));
                        por('logradouro', d.logradouro);
                        por('numero', d.numero);
                        por('bairro', d.bairro);
                        por('cidade', d.cidade);
                        por('uf', d.uf);
                        por('ibge', d.ibge);

                        this.cnpjDosDados = cnpj;
                        this.cnpjPreenchido = true;
                        if (d.situacao && d.situacao.toUpperCase() !== 'ATIVA') {
                            this.cnpjSituacao = `Atenção: na Receita Federal este CNPJ consta como ${d.situacao}.`;
                        }

                        // A Receita grava a cidade em maiúsculas e sem acento
                        // ("VITORIA"). O CEP traz o nome como a nota deve
                        // mostrar, e o código do município junto.
                        if (trocou && this.apenasDigitos(this.cep).length === 8) await this.buscarCep();
                        else this.sugerirLocal();
                    } catch (e) {
                        this.cnpjAviso = 'A busca de CNPJ não respondeu. Preencha na mão.';
                    } finally {
                        this.buscandoCnpj = false;
                    }
                },
            }));
        });
    </script>
    @endpush
</x-app-layout>
