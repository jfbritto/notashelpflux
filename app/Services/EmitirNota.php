<?php

namespace App\Services;

use App\Models\Nota;
use App\Services\Emissor\Emissor;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * O ponto único de emissão: a tela e a API passam por aqui.
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
     * Devolve a nota com `wasRecentlyCreated` dizendo se ela nasceu agora ou
     * já existia: é o que separa o 201 do 200 na API.
     *
     * @param  array<string, mixed>  $dados  colunas da nota
     */
    public function emitir(array $dados): Nota
    {
        // Idempotência: a mesma referência da mesma origem devolve a nota que
        // já existe, sem reenviar. Vale para a API; nota manual não tem
        // referência e nunca cai aqui.
        if ($existente = $this->existente($dados)) {
            return $existente;
        }

        try {
            $nota = Nota::create($dados + ['status' => 'processando']);
        } catch (UniqueConstraintViolationException $e) {
            // Duas chamadas com a mesma referência no mesmo instante (o SaaS
            // repetiu depois de um timeout): as duas passaram pela consulta
            // acima, e o índice único (origem, referencia_externa) barrou a
            // segunda. Ela devolve a que a primeira criou, sem reenviar.
            return $this->existente($dados) ?? throw $e;
        }

        // O desfecho pode vir na hora (recusa de validação) ou depois, pelo
        // webhook. Os dois passam pelo mesmo FecharNota.
        $this->fecharNota->aplicar($nota, $this->emissor->enviar($nota));

        // refresh() e não fresh(): fresh() devolve outra instância e perderia
        // o `wasRecentlyCreated`.
        return $nota->refresh();
    }

    /** @param  array<string, mixed>  $dados */
    private function existente(array $dados): ?Nota
    {
        if (blank($dados['referencia_externa'] ?? null)) {
            return null;
        }

        return Nota::where('origem', $dados['origem'])
            ->where('referencia_externa', $dados['referencia_externa'])
            ->first();
    }
}
