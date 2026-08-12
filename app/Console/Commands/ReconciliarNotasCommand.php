<?php

namespace App\Console\Commands;

use App\Models\Nota;
use App\Services\Emissor\Emissor;
use App\Services\FecharNota;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fecha as notas que ficaram penduradas em "processando".
 *
 * A emissão é assíncrona: quem conclui é o webhook do emissor. Quando esse
 * webhook não chega, a nota fica em processando para sempre, o cliente vê "em
 * emissão" indefinidamente e ninguém é avisado, porque nota parada não é erro:
 * é silêncio. Aconteceu no TreinaEdu em 11/08/2026, com o id do emissor
 * gravado e sem erro nenhum.
 *
 * CONSULTA, nunca reemite. Reenviar uma nota que o emissor já autorizou geraria
 * uma segunda NFS-e do mesmo serviço, o que é problema fiscal e não bug de
 * tela. Por isso só entram notas que já têm id do emissor: sem id não há o que
 * consultar.
 *
 *   php artisan notas:reconciliar               paradas há mais de 30 min
 *   php artisan notas:reconciliar --minutos=0   todas as que estão processando
 *   php artisan notas:reconciliar --dry         mostra o que faria
 */
class ReconciliarNotasCommand extends Command
{
    protected $signature = 'notas:reconciliar
        {--minutos=30 : idade mínima da nota, para não competir com o webhook}
        {--dry : não grava, só relata}';

    protected $description = 'Consulta no emissor as notas paradas em processamento e fecha as que já saíram';

    public function handle(Emissor $emissor, FecharNota $fecharNota): int
    {
        $paradas = Nota::where('status', 'processando')
            ->whereNotNull('notaas_invoice_id')
            ->where('created_at', '<=', now()->subMinutes((int) $this->option('minutos')))
            ->get();

        if ($paradas->isEmpty()) {
            $this->info('Nenhuma nota parada em processamento.');

            return self::SUCCESS;
        }

        $fechadas = $comErro = 0;

        foreach ($paradas as $nota) {
            if ($this->option('dry')) {
                $this->line("  nota {$nota->id}: consultaria {$nota->notaas_invoice_id}");

                continue;
            }

            $retorno = $emissor->consultar($nota->notaas_invoice_id);
            $situacao = $retorno['status'] ?? 'processando';

            if ($situacao === 'processando') {
                // Emissor legítimo demora minutos, não dias. Passando de um
                // dia, alguma coisa está errada do lado de lá e alguém precisa
                // olhar, em vez de a nota seguir consultada em silêncio.
                if ($nota->created_at->lt(now()->subDay())) {
                    Log::warning('Nota parada há mais de um dia no emissor', [
                        'nota' => $nota->id,
                        'notaas_invoice_id' => $nota->notaas_invoice_id,
                        'desde' => $nota->created_at->toDateTimeString(),
                    ]);
                }

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

        $this->info("{$paradas->count()} nota(s) conferida(s). {$fechadas} fechada(s), {$comErro} com erro.");

        return self::SUCCESS;
    }
}
