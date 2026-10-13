<?php

namespace App\Jobs;

use App\Models\Aplicacao;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * O POST do retorno na URL cadastrada da aplicação (spec §5, "O retorno").
 *
 * Mesmo desenho do webhook que a Notaas manda para nós, para o SaaS poder
 * copiar o controller que já tem: corpo `{"event": ..., "data": {...}}`,
 * `X-Notas-Signature: sha256=<HMAC-SHA256 do corpo cru>` com o segredo da
 * aplicação, e o evento repetido em `X-Notas-Event`.
 *
 * O corpo chega PRONTO, montado na hora da transição, e não é remontado a
 * cada tentativa: o que o SaaS recebe na terceira tentativa de um
 * `nota.emitida` é a nota como ela estava ao ser emitida, coerente com o
 * evento. O segredo, ao contrário, é lido aqui e nunca viaja no job: na fila
 * `database` o job fica gravado em texto claro na tabela `jobs`.
 *
 * Falhar aqui nunca muda a nota. Esgotadas as tentativas, a nota continua
 * certa na plataforma e o SaaS a alcança pelo `GET /api/notas/{referencia}`.
 */
class EnviarRetorno implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $aplicacaoId,
        public string $evento,
        public string $corpo,
    ) {}

    /**
     * Espera crescente entre as tentativas: um SaaS fora do ar por um deploy
     * volta em segundos, um com problema de verdade precisa de minutos.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        $aplicacao = Aplicacao::find($this->aplicacaoId);

        // Revogada entre a transição e o envio: quem perdeu a chave também
        // perde o retorno.
        if (! $aplicacao || $aplicacao->estaRevogada()) {
            return;
        }

        $resposta = Http::withHeaders([
            'X-Notas-Event' => $this->evento,
            'X-Notas-Signature' => 'sha256='.hash_hmac('sha256', $this->corpo, $aplicacao->segredo_callback),
        ])
            // O corpo vai CRU, byte a byte o que foi assinado. Deixar o
            // cliente HTTP reserializar um array mudaria espaços ou escapes
            // e a assinatura deixaria de bater do outro lado.
            ->withBody($this->corpo, 'application/json')
            ->acceptJson()
            // Redirecionamento não é entrega: seguir um 302 transformaria o
            // POST num GET para outro endereço.
            ->withoutRedirecting()
            ->timeout(10)
            ->post($aplicacao->callback_url);

        if (! $resposta->successful()) {
            throw new RuntimeException("O SaaS respondeu HTTP {$resposta->status()} ao retorno.");
        }
    }

    public function failed(?Throwable $erro): void
    {
        Log::warning('Retorno à aplicação não entregue; o SaaS alcança a nota pelo GET', [
            'aplicacao' => $this->aplicacaoId,
            'evento' => $this->evento,
            'referencia' => json_decode($this->corpo, true)['data']['referencia'] ?? null,
            'erro' => $erro?->getMessage(),
        ]);
    }
}
