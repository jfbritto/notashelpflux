<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Os perfis são dado fiscal versionado, não tela: mudam raramente e erram
 * caro. Estes valores foram conferidos contra notas reais, e é por isso que
 * eles têm teste: se alguém "arrumar" um código aqui, a nota sai errada e só
 * se descobre depois de emitida.
 */
class PerfilDeServicoTest extends TestCase
{
    public function test_o_perfil_de_nutricao_carrega_os_codigos_da_nota_real(): void
    {
        $perfil = config('fiscal.perfis.nutricao');

        // Conferidos contra a DANFSe de 06/08/2026, emitida pelo portal nacional.
        $this->assertSame('041001', $perfil['codigo_tributacao_nacional']);
        $this->assertSame('4.10', $perfil['item_lista_servico']);
        $this->assertSame('1.2301.99.00', $perfil['nbs']);
        $this->assertSame('tomador', $perfil['local_prestacao_padrao']);
    }

    public function test_o_perfil_de_software_mantem_o_que_o_treinaedu_ja_emite(): void
    {
        $perfil = config('fiscal.perfis.software');

        $this->assertSame('010501', $perfil['codigo_tributacao_nacional']);
        $this->assertSame('1.05', $perfil['item_lista_servico']);
        $this->assertSame('prestador', $perfil['local_prestacao_padrao']);
    }

    /**
     * A trava mais importante do projeto. Se ela cair, um teste pode emitir
     * nota de verdade.
     */
    public function test_em_ambiente_de_teste_o_emissor_e_o_falso_e_nao_ha_chave(): void
    {
        $this->assertSame('fake', config('fiscal.emissor'));
        $this->assertEmpty(config('fiscal.notaas.api_key'));
    }
}
