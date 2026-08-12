<?php

namespace App\Services;

use App\Models\Nota;
use App\Services\Emissor\Emissor;

/**
 * O ponto único de emissão: a tela usa hoje, a API vai usar amanhã.
 *
 * Guardar isto num serviço, e não no controller, é o que garante que a nota
 * criada pela emissão manual e a criada pela API sigam exatamente o mesmo
 * caminho, inclusive na idempotência.
 */
class EmitirNota
{
    public function __construct(
        private Emissor $emissor,
        private FecharNota $fecharNota,
    ) {}

    /**
     * @param  array<string, mixed>  $dados  colunas da nota
     */
    public function emitir(array $dados): Nota
    {
        // Idempotência: a mesma referência da mesma origem devolve a nota que
        // já existe, sem reenviar. Vale para a API; nota manual não tem
        // referência e nunca cai aqui.
        if (filled($dados['referencia_externa'] ?? null)) {
            $existente = Nota::where('origem', $dados['origem'])
                ->where('referencia_externa', $dados['referencia_externa'])
                ->first();

            if ($existente) {
                return $existente;
            }
        }

        $nota = Nota::create($dados + ['status' => 'processando']);

        // O desfecho pode vir na hora (recusa de validação) ou depois, pelo
        // webhook. Os dois passam pelo mesmo FecharNota.
        $this->fecharNota->aplicar($nota, $this->emissor->enviar($nota));

        return $nota->fresh();
    }
}
