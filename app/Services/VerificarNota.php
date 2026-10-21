<?php

namespace App\Services;

use App\Models\Nota;
use App\Services\Emissor\Emissor;
use Illuminate\Support\Facades\Cache;

/**
 * Consulta uma nota pendente na hora, para quem está olhando a tela e não
 * quer esperar a reconciliação (roda a cada 5 min, e só pega o que já está
 * pendente há pelo menos 2 min). Mesma consulta que o cron faz, só que sob
 * demanda, e pela mesma regra: CONSULTA, nunca reemite nem cancela de novo.
 *
 * O cache de alguns segundos por nota é o único freio: sem ele, um clique
 * impaciente vira uma consulta por segundo na Notaas.
 */
class VerificarNota
{
    private const JANELA_SEGUNDOS = 30;

    public function __construct(
        private Emissor $emissor,
        private FecharNota $fecharNota,
    ) {}

    /** @return array{ok: bool, mensagem: string} */
    public function verificar(Nota $nota): array
    {
        if (! $this->pendente($nota)) {
            return ['ok' => false, 'mensagem' => 'Esta nota não tem nada pendente para verificar.'];
        }

        $chave = "nota-verificacao:{$nota->id}";

        if (Cache::has($chave)) {
            return ['ok' => false, 'mensagem' => 'Verificado há pouco. Aguarde um pouco antes de tentar de novo.'];
        }

        Cache::put($chave, true, self::JANELA_SEGUNDOS);

        $retorno = $this->emissor->consultar($nota->notaas_invoice_id);

        // "Buscar PDF": a nota já está autorizada, só os links faltam.
        if ($this->semDocumento($nota)) {
            return $this->fecharNota->anexarDocumentos($nota, $retorno) && $nota->pdf_url
                ? ['ok' => true, 'mensagem' => 'Documentos anexados: o PDF já está na lista.']
                : ['ok' => true, 'mensagem' => 'O emissor ainda não terminou o PDF. Tente de novo em alguns minutos.'];
        }

        $mudou = $this->fecharNota->aplicar($nota, $retorno);

        if (! $mudou) {
            return ['ok' => true, 'mensagem' => 'Ainda sem confirmação do emissor. Tente de novo em instantes.'];
        }

        $nota->refresh();

        return ['ok' => true, 'mensagem' => match ($nota->status) {
            'cancelada' => 'Cancelamento confirmado.',
            'emitida' => 'Nota autorizada pela prefeitura.',
            'erro' => 'O emissor recusou a nota: '.$nota->erro,
            default => 'Situação atualizada.',
        }];
    }

    private function pendente(Nota $nota): bool
    {
        if (blank($nota->notaas_invoice_id)) {
            return false;
        }

        return $nota->status === 'processando'
            || ($nota->status === 'emitida' && $nota->cancelamento_solicitado_em !== null)
            || $this->semDocumento($nota);
    }

    /**
     * Autorizada e sem PDF ou XML: a consulta que a fechou chegou antes de o
     * emissor guardar os documentos, e o aviso de documentos prontos não veio
     * (ver ReconciliarNotasCommand).
     */
    public function semDocumento(Nota $nota): bool
    {
        return $nota->status === 'emitida'
            && $nota->cancelamento_solicitado_em === null
            && ($nota->pdf_url === null || $nota->xml_url === null);
    }
}
