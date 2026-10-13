<?php

namespace App\Services;

use App\Http\Resources\NotaResource;
use App\Jobs\EnviarRetorno;
use App\Models\Aplicacao;
use App\Models\Nota;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decide se a nota tem um SaaS esperando o retorno e, se tem, despacha o
 * envio.
 *
 * Só nota de origem com aplicação ATIVA tem retorno. Nota manual não tem
 * ninguém do outro lado; nota de origem sem aplicação (ou com a aplicação
 * revogada) também não.
 *
 * Quem chama é o FecharNota, que é por onde passam todas as transições
 * (emissão, webhook, reconciliação, "Verificar agora", cancelamento). Por
 * isso o retorno não precisa ser lembrado em cada um desses caminhos, e um
 * caminho novo que feche nota pelo FecharNota já nasce avisando.
 */
class AvisarAplicacao
{
    public function avisar(Nota $nota, string $evento): void
    {
        if ($nota->origem === 'manual') {
            return;
        }

        $aplicacao = Aplicacao::daOrigem($nota->origem);

        if (! $aplicacao) {
            return;
        }

        $corpo = json_encode([
            'event' => $evento,
            'data' => NotaResource::make($nota)->resolve(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        // Falha no retorno nunca pode desfazer nem travar o fechamento da
        // nota. Com a fila `sync` o envio acontece aqui dentro e a exceção da
        // última tentativa sobe até este ponto: sem o catch, um SaaS fora do
        // ar devolveria 500 ao webhook da Notaas (que reenviaria) ou à
        // própria chamada de emissão, com a nota já criada.
        try {
            EnviarRetorno::dispatch($aplicacao->id, $evento, $corpo);
        } catch (Throwable $erro) {
            Log::warning('Retorno à aplicação não enviado', [
                'aplicacao' => $aplicacao->id,
                'nota' => $nota->id,
                'evento' => $evento,
                'erro' => $erro->getMessage(),
            ]);
        }
    }
}
