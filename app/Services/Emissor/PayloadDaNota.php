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
 *   3. O que vai em `servico.codigoMunicipio` é o LOCAL DA PRESTAÇÃO, não o
 *      município do prestador. Numa nota real de nutrição o atendimento foi em
 *      Vitória e o ISS ficou em Santa Maria de Jetibá.
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
                'itemListaServico' => $perfil['item_lista_servico'],
                // A DANFSe imprime o NBS pontuado ("1.2301.99.00"); a API exige
                // os 9 dígitos crus ("123019900") e recusa o pontuado. Aceita-se
                // no config a forma legível, que é a conferível contra a nota, e
                // despe-se aqui, como já se faz com CPF e CEP.
                'nbs' => isset($perfil['nbs']) ? preg_replace('/\D/', '', $perfil['nbs']) : null,
                'descricao' => $nota->descricao,
                'codigoMunicipio' => $nota->local_prestacao_ibge,
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
                'cidade' => $nota->tomador_cidade,
                'uf' => $nota->tomador_uf,
                'codigoMunicipio' => $nota->tomador_ibge,
                'cep' => $nota->tomador_cep,
            ], fn ($v) => filled($v)),
        ], fn ($v) => filled($v));
    }
}
