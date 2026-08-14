<?php

namespace App\Services;

use App\Models\Nota;
use App\Services\Emissor\Emissor;

/**
 * O cancelamento, com as regras num lugar só (a tela usa hoje, a API do
 * plano 2 vai usar amanhã, igual ao EmitirNota).
 *
 * POST /cancelar na Notaas é ASSÍNCRONO: o pedido aceito responde 202 e o
 * desfecho ('cancelada') chega depois, pela consulta ou pelo webhook. Uma
 * nota com cancelamento pendente CONTINUA 'emitida' (o PDF ainda vale até a
 * prefeitura confirmar); `cancelamento_solicitado_em` é o que diz que há algo
 * em andamento, e é o que a reconciliação usa para não deixar isso em
 * silêncio, do mesmo jeito que já faz com emissão pendente.
 *
 * Só se cancela o que foi AUTORIZADO: nota processando ainda pode virar
 * emitida (cancelar no meio do voo não existe no padrão nacional), e nota com
 * erro nunca chegou a ser documento.
 */
class CancelarNota
{
    public function __construct(
        private Emissor $emissor,
        private FecharNota $fecharNota,
    ) {}

    /**
     * @return array{ok: bool, mensagem: string}
     */
    public function cancelar(Nota $nota, string $motivo): array
    {
        if ($nota->status !== 'emitida') {
            return [
                'ok' => false,
                'mensagem' => $nota->status === 'processando'
                    ? 'Esta nota ainda está em emissão. Espere ela fechar: cancelamento vale para nota autorizada.'
                    : 'Só nota emitida pode ser cancelada.',
            ];
        }

        if ($nota->cancelamento_solicitado_em !== null) {
            return ['ok' => false, 'mensagem' => 'O cancelamento desta nota já foi solicitado. Aguarde a confirmação.'];
        }

        if (blank($nota->notaas_invoice_id)) {
            return ['ok' => false, 'mensagem' => 'Esta nota não tem identificador no emissor.'];
        }

        $retorno = $this->emissor->cancelar($nota->notaas_invoice_id, $motivo);
        $situacao = $retorno['status'] ?? null;

        if ($situacao === 'cancelada') {
            $this->fecharNota->aplicar($nota, ['status' => 'cancelada']);
            $nota->update(['motivo_cancelamento' => $motivo]);

            return ['ok' => true, 'mensagem' => 'Nota cancelada no emissor.'];
        }

        if ($situacao === 'processando') {
            // O caso normal: o pedido foi aceito, o desfecho vem depois. A
            // nota permanece 'emitida' (o documento ainda vale), e é este
            // carimbo que a reconciliação usa para ir atrás do desfecho.
            $nota->update(['motivo_cancelamento' => $motivo, 'cancelamento_solicitado_em' => now()]);

            return [
                'ok' => true,
                'mensagem' => 'Cancelamento solicitado. A situação muda para "Cancelada" assim que o emissor confirmar '
                    .'(costuma levar alguns minutos).',
            ];
        }

        // O pedido falhou de verdade (não é o caso assíncrono normal). A nota
        // pode ter sido cancelada por fora (painel da Notaas); consultar custa
        // nada e evita mostrar erro para uma nota que já está no estado
        // desejado.
        $consulta = $this->emissor->consultar($nota->notaas_invoice_id);

        if (($consulta['status'] ?? null) === 'cancelada') {
            $this->fecharNota->aplicar($nota, $consulta);
            $nota->update(['motivo_cancelamento' => $motivo]);

            return ['ok' => true, 'mensagem' => 'A nota já constava cancelada no emissor. Situação sincronizada.'];
        }

        return [
            'ok' => false,
            'mensagem' => 'O emissor recusou o cancelamento: '.($retorno['erro'] ?? 'erro desconhecido')
                .' Se preferir, cancele pelo painel da Notaas; a plataforma sincroniza ao consultar.',
        ];
    }
}
