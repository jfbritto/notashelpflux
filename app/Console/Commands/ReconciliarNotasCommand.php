<?php

namespace App\Console\Commands;

use App\Models\Nota;
use App\Services\Emissor\Emissor;
use App\Services\FecharNota;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fecha o que ficou pendente no emissor: emissão parada em "processando" e
 * cancelamento solicitado que ainda não confirmou.
 *
 * Os dois são assíncronos por natureza (o POST responde 202 e o desfecho vem
 * depois), e os dois têm o mesmo risco: sem alguém ir atrás, ficam parados em
 * silêncio para sempre. Aconteceu com emissão no TreinaEdu em 11/08/2026, com
 * o id do emissor gravado e sem erro nenhum.
 *
 * CONSULTA, nunca reemite nem cancela de novo. Repetir o pedido enquanto o
 * primeiro ainda não fechou arrisca duplicar o efeito colateral (nota
 * duplicada, ou um segundo pedido de cancelamento sobre o mesmo).
 *
 *   php artisan notas:reconciliar               parado há mais de 30 min
 *   php artisan notas:reconciliar --minutos=0   tudo que está pendente
 *   php artisan notas:reconciliar --dry         mostra o que faria
 */
class ReconciliarNotasCommand extends Command
{
    protected $signature = 'notas:reconciliar
        {--minutos=30 : idade mínima do pendente, para não competir com o webhook}
        {--dry : não grava, só relata}';

    protected $description = 'Consulta no emissor as notas com emissão ou cancelamento pendente e fecha as que já saíram';

    public function handle(Emissor $emissor, FecharNota $fecharNota): int
    {
        $minutos = (int) $this->option('minutos');
        $corte = now()->subMinutes($minutos);

        $emissaoPendente = Nota::where('status', 'processando')
            ->whereNotNull('notaas_invoice_id')
            ->where('created_at', '<=', $corte)
            ->get();

        $cancelamentoPendente = Nota::where('status', 'emitida')
            ->whereNotNull('cancelamento_solicitado_em')
            ->where('cancelamento_solicitado_em', '<=', $corte)
            ->get();

        if ($emissaoPendente->isEmpty() && $cancelamentoPendente->isEmpty()) {
            $this->info('Nada pendente.');

            return self::SUCCESS;
        }

        $fechadas = $comErro = $confirmadas = 0;

        foreach ($emissaoPendente as $nota) {
            if ($this->option('dry')) {
                $this->line("  nota {$nota->id}: consultaria emissão ({$nota->notaas_invoice_id})");

                continue;
            }

            $retorno = $emissor->consultar($nota->notaas_invoice_id);
            $situacao = $retorno['status'] ?? 'processando';

            if ($situacao === 'processando') {
                $this->avisarSeMuitoTempo($nota, $nota->created_at, 'emissão');

                continue;
            }

            $fecharNota->aplicar($nota, $retorno);
            $nota->refresh();

            if ($situacao === 'emitida') {
                $fechadas++;
                $this->line("  nota {$nota->id}: autorizada, nº {$nota->numero}");
            } elseif ($situacao === 'erro') {
                $comErro++;
                $this->error("  nota {$nota->id}: recusada - {$nota->erro}");
            }
        }

        foreach ($cancelamentoPendente as $nota) {
            if ($this->option('dry')) {
                $this->line("  nota {$nota->id}: consultaria cancelamento ({$nota->notaas_invoice_id})");

                continue;
            }

            $retorno = $emissor->consultar($nota->notaas_invoice_id);
            $situacao = $retorno['status'] ?? 'processando';

            if ($situacao !== 'cancelada') {
                $this->avisarSeMuitoTempo($nota, $nota->cancelamento_solicitado_em, 'cancelamento');

                continue;
            }

            $fecharNota->aplicar($nota, $retorno);
            $confirmadas++;
            $this->line("  nota {$nota->id}: cancelamento confirmado");
        }

        $this->info(
            "{$emissaoPendente->count()} emissão(ões) conferida(s): {$fechadas} fechada(s), {$comErro} com erro. "
            ."{$cancelamentoPendente->count()} cancelamento(s) conferido(s): {$confirmadas} confirmado(s)."
        );

        return self::SUCCESS;
    }

    /** Emissor legítimo demora minutos, não dias. */
    private function avisarSeMuitoTempo(Nota $nota, \Illuminate\Support\Carbon $desde, string $tipo): void
    {
        if ($desde->lt(now()->subDay())) {
            Log::warning("Nota com {$tipo} parado há mais de um dia no emissor", [
                'nota' => $nota->id,
                'notaas_invoice_id' => $nota->notaas_invoice_id,
                'desde' => $desde->toDateTimeString(),
            ]);
        }
    }
}
