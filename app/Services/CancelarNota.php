<?php

namespace App\Services;

use App\Models\Nota;
use App\Services\Emissor\Emissor;

/**
 * O cancelamento, com as regras num lugar só (a tela usa hoje, a API do
 * plano 2 vai usar amanhã, igual ao EmitirNota).
 *
 * Só se cancela o que foi AUTORIZADO: nota processando ainda pode virar
 * emitida (cancelar no meio do voo não existe no padrão nacional), e nota com
 * erro nunca chegou a ser documento.
 *
 * Se o emissor recusar o pedido, antes de desistir a nota é CONSULTADA: ela
 * pode já ter sido cancelada pelo painel da Notaas, e sem webhook na fase 1
 * este é o momento de sincronizar isso.
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

        if (blank($nota->notaas_invoice_id)) {
            return ['ok' => false, 'mensagem' => 'Esta nota não tem identificador no emissor.'];
        }

        $retorno = $this->emissor->cancelar($nota->notaas_invoice_id, $motivo);

        if (($retorno['status'] ?? null) === 'cancelada') {
            $this->fecharNota->aplicar($nota, ['status' => 'cancelada']);
            $nota->update(['motivo_cancelamento' => $motivo]);

            return ['ok' => true, 'mensagem' => 'Nota cancelada no emissor.'];
        }

        // O pedido falhou; a nota pode ter sido cancelada por fora (painel da
        // Notaas). Consultar custa nada e evita mostrar erro para uma nota que
        // já está no estado desejado.
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
