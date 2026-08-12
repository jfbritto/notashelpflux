<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmitirNotaRequest;
use App\Models\Nota;
use App\Services\EmitirNota;
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
        $notas = Nota::query()
            ->when(! $request->user()->ehAdmin(), fn ($q) => $q->where('origem', 'manual'))
            ->latest('id')
            ->paginate(20);

        return view('notas.index', compact('notas'));
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

        return view('notas.create', [
            'modelo' => $modelo,
            'perfil' => config('fiscal.perfis.nutricao'),
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
            // O perfil vem daqui, nunca do formulário: é dado fiscal.
            'perfil' => 'nutricao',
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
