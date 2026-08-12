<?php

namespace App\Services;

use App\Models\Nota;

/**
 * Aplica na nota um desfecho vindo do emissor.
 *
 * Existe como serviço, e não copiado em cada caminho, porque a emissão termina
 * em três lugares (o próprio envio, o webhook e a reconciliação). No TreinaEdu
 * essa regra estava repetida em cada um deles e precisou ser corrigida três
 * vezes, em três momentos diferentes, sempre com um cliente sem a nota dele.
 *
 * Duas regras moram aqui:
 *
 *   1. Os LINKS dos documentos são aplicados sempre. Eles chegam depois da
 *      emissão, em evento próprio, e às vezes duas vezes (XML antes do PDF).
 *      Anexar link não é mudar de estado.
 *   2. O ESTADO só avança. Nota fechada não volta para processando, e quem
 *      aplica um estado que já era o atual não gera transição, então não
 *      dispara aviso nenhum.
 */
class FecharNota
{
    /**
     * @param  array<string, mixed>  $retorno  no formato do contrato Emissor
     * @return bool  houve transição de estado
     */
    public function aplicar(Nota $nota, array $retorno): bool
    {
        $anterior = $nota->status;
        $situacao = $retorno['status'] ?? 'processando';

        $mudancas = array_filter([
            'numero' => $retorno['numero'] ?? null,
            'chave_acesso' => $retorno['chave_acesso'] ?? null,
            'pdf_url' => $retorno['pdf_url'] ?? null,
            'xml_url' => $retorno['xml_url'] ?? null,
            'erro' => $retorno['erro'] ?? null,
        ], fn ($v) => $v !== null);

        // "processando" não fecha nada: pode ser o eco de uma consulta feita
        // enquanto o emissor ainda pensa. Os documentos que vierem junto,
        // porém, entram.
        if ($situacao !== 'processando' && $nota->status !== $situacao) {
            $mudancas['status'] = $situacao;

            if ($situacao === 'emitida') {
                $mudancas['emitida_em'] = now();
            }
        }

        if ($mudancas !== []) {
            $nota->update($mudancas);
        }

        return $nota->fresh()->status !== $anterior;
    }
}
