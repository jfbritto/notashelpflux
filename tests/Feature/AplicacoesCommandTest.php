<?php

namespace Tests\Feature;

use App\Models\Aplicacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AplicacoesCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: int, 1: string, 2: string} código de saída, chave e segredo mostrados */
    private function criar(string ...$argumentos): array
    {
        $codigo = Artisan::call('aplicacoes:criar', array_combine(
            ['nome', 'origem', 'perfil', 'callback_url'],
            $argumentos,
        ));
        $saida = Artisan::output();

        preg_match('/x-api-key\):\s+([0-9a-f]{64})/', $saida, $chave);
        preg_match('/X-Notas-Signature\):\s+([0-9a-f]{64})/', $saida, $segredo);

        return [$codigo, $chave[1] ?? '', $segredo[1] ?? ''];
    }

    /**
     * Os dois segredos, cada um com a sua regra: a chave vira hash (não há
     * como recuperá-la), o segredo do retorno fica cifrado (precisa ser lido
     * para assinar). Nenhum dos dois fica em claro no banco.
     */
    public function test_criar_mostra_chave_e_segredo_e_guarda_cada_um_do_seu_jeito(): void
    {
        [$codigo, $chave, $segredo] = $this->criar('Hotel em Dia', 'hotelemdia', 'software', 'https://hotelemdia.test/webhooks/notas');

        $this->assertSame(0, $codigo);
        $this->assertNotSame('', $chave);
        $this->assertNotSame('', $segredo);
        $this->assertNotSame($chave, $segredo);

        $aplicacao = Aplicacao::sole();
        $this->assertSame('hotelemdia', $aplicacao->origem);
        $this->assertSame('software', $aplicacao->perfil);
        $this->assertSame(hash('sha256', $chave), $aplicacao->chave_hash);
        $this->assertSame($segredo, $aplicacao->segredo_callback);

        $linha = (array) DB::table('aplicacoes')->first();
        $this->assertStringNotContainsString($chave, json_encode($linha));
        $this->assertStringNotContainsString($segredo, json_encode($linha));

        // E a chave mostrada é a que funciona na API.
        $this->withHeaders(['x-api-key' => $chave])->getJson('/api/notas')->assertOk();
    }

    /** `manual` é a tela; uma aplicação com ela misturaria as notas da emissora com as da API. */
    public function test_origem_manual_ou_desconhecida_e_recusada(): void
    {
        $this->assertSame(1, $this->criar('X', 'manual', 'software', 'https://x.test/r')[0]);
        $this->assertSame(1, $this->criar('X', 'outro', 'software', 'https://x.test/r')[0]);
        $this->assertSame(0, Aplicacao::count());
    }

    /**
     * A API não recebe o IBGE do tomador; perfil cujo local da prestação é o
     * do tomador (nutrição) só se emite pela tela.
     */
    public function test_perfil_desconhecido_ou_com_local_do_tomador_e_recusado(): void
    {
        $this->assertSame(1, $this->criar('X', 'helpdiet', 'inexistente', 'https://x.test/r')[0]);
        $this->assertSame(1, $this->criar('X', 'helpdiet', 'nutricao', 'https://x.test/r')[0]);
        $this->assertSame(0, Aplicacao::count());
    }

    /** O retorno leva dados fiscais do cliente: em http viajariam em claro. */
    public function test_url_de_retorno_precisa_ser_https(): void
    {
        $this->assertSame(1, $this->criar('X', 'hotelemdia', 'software', 'http://hotelemdia.test/r')[0]);
        $this->assertSame(1, $this->criar('X', 'hotelemdia', 'software', 'nao-e-url')[0]);
        $this->assertSame(0, Aplicacao::count());
    }

    public function test_revogar_corta_a_chave_e_mantem_a_linha(): void
    {
        [, $chave] = $this->criar('Hotel em Dia', 'hotelemdia', 'software', 'https://hotelemdia.test/webhooks/notas');
        $aplicacao = Aplicacao::sole();

        $this->artisan('aplicacoes:revogar', ['id' => $aplicacao->id])->assertSuccessful();

        $this->assertNotNull($aplicacao->fresh()->revogada_em);
        $this->withHeaders(['x-api-key' => $chave])->getJson('/api/notas')->assertStatus(401);

        // Revogar de novo não estraga a data original.
        $data = $aplicacao->fresh()->revogada_em;
        $this->travel(1)->days();
        $this->artisan('aplicacoes:revogar', ['id' => $aplicacao->id])->assertSuccessful();
        $this->assertEquals($data, $aplicacao->fresh()->revogada_em);
    }

    public function test_revogar_aplicacao_inexistente_falha(): void
    {
        $this->artisan('aplicacoes:revogar', ['id' => 999])->assertFailed();
    }
}
