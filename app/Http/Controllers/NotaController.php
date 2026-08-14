<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmitirNotaRequest;
use App\Models\Nota;
use App\Services\CancelarNota;
use App\Services\EmitirNota;
use App\Services\VerificarNota;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotaController extends Controller
{
    /**
     * O admin vê todas as origens; a emissora vê as notas manuais, que são as
     * dela. É a diferença entre acompanhar a operação e emitir.
     */
    public function index(Request $request): View
    {
        $perfis = config('fiscal.perfis');

        $notas = Nota::query()
            ->with('autora')
            ->when(! $request->user()->ehAdmin(), fn ($q) => $q->where('origem', 'manual'))
            // Filtros de valor fechado: chave de perfil que não existe no
            // config e origem fora da lista são simplesmente ignoradas.
            ->when(
                $request->filled('perfil') && isset($perfis[$request->query('perfil')]),
                fn ($q) => $q->where('perfil', $request->query('perfil')),
            )
            ->when(
                in_array($request->query('origem'), ['manual', 'treinaedu', 'helpdiet'], true),
                fn ($q) => $q->where('origem', $request->query('origem')),
            )
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('notas.index', ['notas' => $notas, 'perfis' => $perfis]);
    }

    /**
     * Cancela uma nota autorizada. A justificativa é exigência do padrão
     * nacional, e o mínimo evita "teste" virar motivo em documento fiscal.
     */
    public function cancelar(Request $request, Nota $nota, CancelarNota $cancelarNota)
    {
        // O mesmo recorte da lista: quem não é admin só alcança nota manual.
        abort_unless($request->user()->ehAdmin() || $nota->origem === 'manual', 403);

        $dados = $request->validate(
            // max:255 é o teto do emissor (campo `motivo` do POST /cancelar);
            // passar disso a Notaas recusaria o pedido.
            ['motivo' => ['required', 'string', 'min:15', 'max:255']],
            ['motivo.min' => 'Explique o motivo do cancelamento (mínimo 15 caracteres): ele sai no evento fiscal.'],
        );

        $resultado = $cancelarNota->cancelar($nota, $dados['motivo']);

        return redirect()->route('notas.index')
            ->with($resultado['ok'] ? 'sucesso' : 'erro', $resultado['mensagem']);
    }

    /**
     * Consulta a nota na hora, para quem não quer esperar a reconciliação
     * (roda a cada 5 min). Mesmo recorte de quem pode mexer na nota.
     */
    public function verificar(Request $request, Nota $nota, VerificarNota $verificarNota)
    {
        abort_unless($request->user()->ehAdmin() || $nota->origem === 'manual', 403);

        $resultado = $verificarNota->verificar($nota);

        return redirect()->route('notas.index')
            ->with($resultado['ok'] ? 'sucesso' : 'erro', $resultado['mensagem']);
    }

    /**
     * O formulário. Aceita `repetir` para reabrir preenchido com os dados de
     * uma nota anterior, que é o que resolve o paciente recorrente sem
     * construir cadastro de pacientes.
     */
    public function create(Request $request): View
    {
        $modelo = null;

        if ($id = $request->query('repetir')) {
            $modelo = Nota::where('origem', 'manual')->find($id);
        }

        // Só os perfis que se emitem à mão. O licenciamento de SaaS sai
        // sozinho a cada cobrança; deixá-lo aqui convidaria a emitir de novo,
        // na mão, uma nota que já saiu.
        $perfis = collect(config('fiscal.perfis'))->filter(fn ($p) => $p['manual'] ?? true);

        return view('notas.create', [
            'modelo' => $modelo,
            'perfis' => $perfis,
            'prestador' => config('fiscal.prestador'),
        ]);
    }

    public function store(EmitirNotaRequest $request, EmitirNota $emitirNota)
    {
        $dados = $request->validated();

        // array_merge e não `+`: com `+` a chave da ESQUERDA vence, e as
        // limpezas abaixo seriam descartadas em favor do que veio do
        // formulário (o documento chegaria mascarado numa coluna de 14).
        $nota = $emitirNota->emitir(array_merge($dados, [
            'origem' => 'manual',
            'referencia_externa' => null,
            // O perfil vem do formulário, mas só pode ser um dos definidos em
            // config/fiscal.php (a validação recusa o resto). Os códigos de
            // tributação continuam saindo do servidor, nunca do navegador.
            'competencia' => now()->format('Y-m'),
            'tomador_documento' => preg_replace('/\D/', '', $dados['tomador_documento']),
            'tomador_cep' => preg_replace('/\D/', '', (string) ($dados['tomador_cep'] ?? '')) ?: null,
            'criada_por' => $request->user()->id,
        ]));

        return redirect()->route('notas.index')->with(
            'sucesso',
            $nota->status === 'erro'
                ? 'O emissor recusou a nota: '.$nota->erro
                : 'Nota enviada para emissão. Ela aparece como emitida assim que a prefeitura autorizar.',
        );
    }
}
