<?php

namespace App\Services;

use App\Models\Nota;
use Illuminate\Support\Facades\DB;

/**
 * Aplica na nota um desfecho vindo do emissor.
 *
 * Existe como serviço, e não copiado em cada caminho, porque a emissão termina
 * em três lugares (o próprio envio, o webhook e a reconciliação). No TreinaEdu
 * essa regra estava repetida em cada um deles e precisou ser corrigida três
 * vezes, em três momentos diferentes, sempre com um cliente sem a nota dele.
 *
 * Três regras moram aqui:
 *
 *   1. Os LINKS dos documentos são aplicados sempre. Eles chegam depois da
 *      emissão, em evento próprio, e às vezes duas vezes (XML antes do PDF).
 *      Anexar link não é mudar de estado.
 *   2. O ESTADO só avança. Nota fechada não volta para processando, e quem
 *      aplica um estado que já era o atual não gera transição, então não
 *      dispara aviso nenhum.
 *   3. O RETORNO ao SaaS sai daqui (AvisarAplicacao), e só quando algo mudou:
 *      `nota.emitida`, `nota.erro` ou `nota.cancelada` na transição de
 *      estado, `nota.documentos` quando chega link novo sem transição. Por
 *      morar aqui, todo caminho que fecha nota (emissão, webhook,
 *      reconciliação, "Verificar agora", cancelamento) avisa sem precisar se
 *      lembrar disso.
 */
class FecharNota
{
    /** O evento do retorno para cada transição de estado. */
    private const EVENTOS = [
        'emitida' => 'nota.emitida',
        'erro' => 'nota.erro',
        'cancelada' => 'nota.cancelada',
    ];

    public function __construct(private AvisarAplicacao $avisarAplicacao) {}

    /**
     * @param  array<string, mixed>  $retorno  no formato do contrato Emissor
     * @return bool houve transição de estado
     */
    public function aplicar(Nota $nota, array $retorno): bool
    {
        $situacao = $retorno['status'] ?? 'processando';

        // Lê e grava com a linha travada. Webhook e reconciliação podem fechar
        // a mesma nota no mesmo segundo (spec §13), e os dois partiriam de
        // "processando": sem a trava, os dois veriam transição e o SaaS
        // receberia `nota.emitida` duas vezes. Com ela, o segundo já lê a nota
        // fechada pelo primeiro e não vê transição nenhuma.
        [$transicao, $evento] = DB::transaction(function () use ($nota, $retorno, $situacao) {
            $atual = Nota::whereKey($nota->getKey())->lockForUpdate()->firstOrFail();
            $documentosAntes = [$atual->pdf_url, $atual->xml_url];

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
            $transicao = $situacao !== 'processando' && $atual->status !== $situacao;

            if ($transicao) {
                $mudancas['status'] = $situacao;

                if ($situacao === 'emitida') {
                    $mudancas['emitida_em'] = now();
                }
            }

            if ($mudancas !== []) {
                $atual->update($mudancas);
            }

            if ($transicao) {
                return [true, self::EVENTOS[$situacao] ?? null];
            }

            // Link novo sem transição: o `nfse.documents_ready` que chega
            // depois da emissão. Repetido com o mesmo link não é novidade e
            // não gera retorno.
            return [false, [$atual->pdf_url, $atual->xml_url] !== $documentosAntes ? 'nota.documentos' : null];
        });

        // Quem chamou continua com a instância dele; ela passa a refletir o
        // que foi gravado, como antes, quando o update era feito nela.
        $nota->refresh();

        // Fora da transação: com a fila `sync` o POST ao SaaS acontece aqui
        // dentro, e não pode segurar a trava da linha enquanto espera a rede.
        if ($evento !== null) {
            $this->avisarAplicacao->avisar($nota, $evento);
        }

        return $transicao;
    }

    /**
     * Só os links de PDF e XML de um retorno do emissor, sem mexer na
     * situação: é o que o nfse.documents_ready faz no webhook, e o que a
     * reconciliação e o "Buscar PDF" fazem com a nota emitida que ficou sem
     * documento. Uma consulta que respondesse "erro" ou "processando" para
     * uma nota já autorizada não pode desfazê-la por esse caminho.
     *
     * @param  array<string, mixed>  $retorno  no formato do contrato Emissor
     * @return bool algum link novo foi anexado
     */
    public function anexarDocumentos(Nota $nota, array $retorno): bool
    {
        $antes = [$nota->pdf_url, $nota->xml_url];

        $this->aplicar($nota, [
            'status' => 'processando',
            'pdf_url' => $retorno['pdf_url'] ?? null,
            'xml_url' => $retorno['xml_url'] ?? null,
        ]);

        return [$nota->pdf_url, $nota->xml_url] !== $antes;
    }
}
