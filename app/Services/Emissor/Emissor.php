<?php

namespace App\Services\Emissor;

use App\Models\Nota;

/**
 * Quem fala com o mundo fiscal.
 *
 * Duas funções, porque a emissão é assíncrona: manda-se a nota e pergunta-se
 * depois o que aconteceu com ela. O retorno é sempre o mesmo formato, no NOSSO
 * vocabulário, para que o resto do sistema não precise conhecer o dialeto de
 * nenhum emissor:
 *
 *   ['status' => 'processando'|'emitida'|'erro'|'cancelada',
 *    'numero' => ?string, 'chave_acesso' => ?string,
 *    'pdf_url' => ?string, 'xml_url' => ?string, 'erro' => ?string]
 *
 * CONTRATO ADICIONAL, e ele não é opcional: `enviar()` grava
 * `notas.notaas_invoice_id` com o id que o emissor devolveu. Esse id é o ÚNICO
 * elo entre a nossa linha e a nota do emissor. Sem ele o webhook chega e não há
 * como saber de que nota ele fala, e a reconciliação não tem o que consultar.
 * Vale para as duas implementações, inclusive a falsa.
 */
interface Emissor
{
    /** @return array<string, mixed> */
    public function enviar(Nota $nota): array;

    /** @return array<string, mixed> */
    public function consultar(string $idNoEmissor): array;
}
