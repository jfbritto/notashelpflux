<?php

namespace App\Services\Emissor;

use App\Models\Nota;

/**
 * O corpo da emissão.
 *
 * Isolado do resto porque é aqui que moram as armadilhas do padrão nacional,
 * cada uma paga uma vez na migração do TreinaEdu:
 *
 *   1. O PRESTADOR não vai no corpo. Ele é o dono da chave de API, com o
 *      certificado na conta do emissor. Mandar a inscrição municipal dele faz
 *      a Notaas recusar com E0120.
 *   2. `tomador.endereco` exige `cidade` e `uf` junto do código IBGE. Só o
 *      código não passa: a API recusa antes de chegar na prefeitura.
 *   3. O que vai em `servico.localPrestacao` é o LOCAL DA PRESTAÇÃO, não o
 *      município do prestador (a Notaas resolve o município de incidência do
 *      ISS sozinha, pela LC 116, a partir deste campo — não é preciso, e não
 *      adiantaria, mandar os dois). Numa nota real de nutrição o atendimento
 *      foi em Vitória e o ISS ficou em Santa Maria de Jetibá.
 *
 *      A primeira versão deste payload usava o nome errado
 *      (`codigoMunicipio`), que a Notaas ignora silenciosamente e substitui
 *      pelo padrão do projeto (o prestador): a nota de 14/08/2026 saiu com o
 *      local do prestador em vez do escolhido na tela, sem nenhum erro. Nome
 *      de campo errado que a API aceita sem reclamar é o pior tipo de defeito
 *      de payload, porque não avisa. Conferido contra
 *      https://docs.notaas.com.br/endpoints.
 */
class PayloadDaNota
{
    /** @return array<string, mixed> */
    public function montar(Nota $nota): array
    {
        $perfil = $nota->perfilDeServico();

        return array_filter([
            // Nossa referência viaja junto quando existe: ajuda a conciliar na
            // conta do emissor e a reconhecer a nota se o id dele se perder.
            'referencia' => $nota->referencia_externa,
            'competencia' => $nota->competencia,
            'tomador' => $this->tomador($nota),
            'servico' => array_filter([
                'codigo' => $perfil['codigo_tributacao_nacional'],
                // NÃO EXISTE campo para o item da LC 116 (tipo "4.10") no
                // contrato deles: o único código de tributação é este
                // ('codigo', o cTribNac de 6 dígitos). `item_lista_servico`
                // segue no config só como metadado para humano — nunca foi e
                // não deve ser mandado no payload.
                'nbs' => isset($perfil['nbs']) ? preg_replace('/\D/', '', $perfil['nbs']) : null,
                'descricao' => $nota->descricao,
                'localPrestacao' => $nota->local_prestacao_ibge,
            ], fn ($v) => $v !== null),
            'valores' => [
                'total' => (float) $nota->valor,
                'aliquotaIss' => (float) $perfil['aliquota'],
                'issRetido' => false,
            ],
        ], fn ($v) => $v !== null);
    }

    /** @return array<string, mixed> */
    private function tomador(Nota $nota): array
    {
        $documento = preg_replace('/\D/', '', (string) $nota->tomador_documento);

        return array_filter([
            ($nota->tomador_tipo === 'pf' ? 'cpf' : 'cnpj') => $documento,
            'nome' => $nota->tomador_nome,
            'email' => $nota->tomador_email,
            'endereco' => array_filter([
                'logradouro' => $nota->tomador_logradouro,
                'numero' => $nota->tomador_numero,
                'complemento' => $nota->tomador_complemento,
                'bairro' => $nota->tomador_bairro,
                // Cidade e UF junto do código: a API recusa o endereço sem eles.
                // NÃO existe `codigoMunicipio` em tomador.endereco — a Notaas
                // resolve o IBGE do tomador sozinha a partir de cidade + uf,
                // então $nota->tomador_ibge nunca precisou ir no payload.
                'cidade' => $nota->tomador_cidade,
                'uf' => $nota->tomador_uf,
                'cep' => $nota->tomador_cep,
            ], fn ($v) => filled($v)),
        ], fn ($v) => filled($v));
    }
}
