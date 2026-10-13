<?php

namespace Tests\Feature;

use App\Jobs\EnviarRetorno;
use App\Models\Aplicacao;
use App\Models\Nota;
use App\Models\User;
use App\Services\Emissor\EmissorFalso;
use App\Services\FecharNota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * O retorno aos SaaS (spec §5, "O retorno"). A fila é `sync` nos testes
 * (phpunit.xml), então o POST acontece na hora e cai no Http::fake.
 */
class RetornoAplicacaoTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://hotelemdia.test/webhooks/notas';

    private const SEGREDO = 'segredo-do-retorno-do-hotel';

    /**
     * O que o SaaS responde. Num closure, e não em Http::fake por teste,
     * porque o primeiro fake registrado vence: um segundo Http::fake no meio
     * do teste seria ignorado em silêncio.
     */
    private int $statusDoSaas = 200;

    private array $headersDoSaas = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(fn () => Http::response(['ok' => true], $this->statusDoSaas, $this->headersDoSaas));
        // Sem segredo da Notaas configurado, o webhook dela passa sem
        // assinatura (o estado do primeiro dia); aqui interessa o outro lado.
        config(['fiscal.notaas.webhook_secret' => null]);
    }

    protected function tearDown(): void
    {
        EmissorFalso::esquecer();
        parent::tearDown();
    }

    private function aplicacao(array $atributos = []): Aplicacao
    {
        return Aplicacao::factory()->create($atributos + [
            'callback_url' => self::URL,
            'segredo_callback' => self::SEGREDO,
        ]);
    }

    private function notaDoHotel(array $atributos = []): Nota
    {
        return Nota::factory()->create($atributos + [
            'origem' => 'hotelemdia',
            'perfil' => 'software',
            'referencia_externa' => 'fatura-1',
            'notaas_invoice_id' => 'abc-123',
        ]);
    }

    /** @param  array<string, mixed>  $corpo */
    private function webhookDaNotaas(array $corpo)
    {
        return $this->postJson('/webhooks/notaas', $corpo);
    }

    /** @return array<int, Request> */
    private function retornos(): array
    {
        return Http::recorded(fn (Request $r) => $r->url() === self::URL)
            ->map(fn ($par) => $par[0])
            ->all();
    }

    /**
     * O caminho de ponta a ponta: a Notaas avisa que emitiu, a plataforma
     * fecha a nota e avisa o SaaS, assinando com o segredo DA APLICAÇÃO.
     */
    public function test_webhook_da_notaas_fecha_a_nota_e_dispara_o_retorno_assinado(): void
    {
        $this->aplicacao();
        $nota = $this->notaDoHotel();
        $chave = '32045592258063432000121000000000000126089909776545';

        $this->webhookDaNotaas(['event' => 'nfse.issued', 'data' => [
            'invoiceId' => 'abc-123', 'status' => 'issued', 'chNFSe' => $chave,
            'pdfUrl' => 'https://storage.notaas.test/n.pdf',
        ]])->assertOk();

        $this->assertSame('emitida', $nota->fresh()->status);

        $retornos = $this->retornos();
        $this->assertCount(1, $retornos);
        [$retorno] = $retornos;

        $this->assertSame('POST', $retorno->method());
        $this->assertSame('nota.emitida', $retorno->header('X-Notas-Event')[0]);

        // A assinatura é do corpo CRU, com o segredo da aplicação. É o que
        // o SaaS confere do lado de lá; outro segredo não bate.
        $this->assertSame(
            'sha256='.hash_hmac('sha256', $retorno->body(), self::SEGREDO),
            $retorno->header('X-Notas-Signature')[0],
        );
        $this->assertNotSame(
            'sha256='.hash_hmac('sha256', $retorno->body(), 'outro-segredo'),
            $retorno->header('X-Notas-Signature')[0],
        );

        $corpo = json_decode($retorno->body(), true);
        $this->assertSame('nota.emitida', $corpo['event']);
        $this->assertSame('fatura-1', $corpo['data']['referencia']);
        $this->assertSame('emitida', $corpo['data']['status']);
        $this->assertSame($chave, $corpo['data']['chave_acesso']);
        $this->assertSame('https://storage.notaas.test/n.pdf', $corpo['data']['pdf_url']);
        $this->assertNotNull($corpo['data']['emitida_em']);
    }

    /**
     * O quarto evento, o que quase ficou de fora: documentos que chegam
     * DEPOIS da emissão viram `nota.documentos`, com os links, e o status não
     * se mexe. Repetido com o mesmo link, não é novidade e não sai de novo.
     */
    public function test_documents_ready_vira_nota_documentos_sem_mudar_o_status(): void
    {
        $this->aplicacao();
        $nota = $this->notaDoHotel(['status' => 'emitida', 'emitida_em' => now()]);

        $documentos = ['event' => 'nfse.documents_ready', 'data' => [
            'invoiceId' => 'abc-123', 'xmlUrl' => 'https://storage.notaas.test/n.xml',
        ]];

        $this->webhookDaNotaas($documentos)->assertOk();
        $this->webhookDaNotaas($documentos)->assertOk();

        $this->assertSame('emitida', $nota->fresh()->status);
        $this->assertSame('https://storage.notaas.test/n.xml', $nota->fresh()->xml_url);

        $retornos = $this->retornos();
        $this->assertCount(1, $retornos);
        $this->assertSame('nota.documentos', $retornos[0]->header('X-Notas-Event')[0]);

        $corpo = json_decode($retornos[0]->body(), true);
        $this->assertSame('nota.documentos', $corpo['event']);
        $this->assertSame('emitida', $corpo['data']['status']);
        $this->assertSame('https://storage.notaas.test/n.xml', $corpo['data']['xml_url']);

        // O PDF chega depois do XML: é link novo, então é outro aviso.
        $this->webhookDaNotaas(['event' => 'nfse.documents_ready', 'data' => [
            'invoiceId' => 'abc-123', 'pdfUrl' => 'https://storage.notaas.test/n.pdf',
        ]])->assertOk();

        $this->assertCount(2, $this->retornos());
    }

    /** Webhook e consulta fechando a mesma nota: o SaaS ouve uma vez só. */
    public function test_o_mesmo_desfecho_aplicado_duas_vezes_avisa_uma_vez(): void
    {
        $this->aplicacao();
        $nota = $this->notaDoHotel();

        app(FecharNota::class)->aplicar($nota, ['status' => 'emitida', 'chave_acesso' => '123']);
        app(FecharNota::class)->aplicar(Nota::find($nota->id), ['status' => 'emitida', 'chave_acesso' => '123']);

        $this->assertCount(1, $this->retornos());
    }

    public function test_a_reconciliacao_dispara_nota_erro(): void
    {
        $this->aplicacao();
        $nota = $this->notaDoHotel();
        $nota->forceFill(['created_at' => now()->subHours(2)])->save();
        EmissorFalso::responderConsultaCom(['status' => 'erro', 'erro' => 'E0120 inscricao do prestador']);

        $this->artisan('notas:reconciliar')->assertSuccessful();

        $retornos = $this->retornos();
        $this->assertCount(1, $retornos);
        $this->assertSame('nota.erro', $retornos[0]->header('X-Notas-Event')[0]);
        $this->assertSame('E0120 inscricao do prestador', json_decode($retornos[0]->body(), true)['data']['erro']);
    }

    public function test_verificar_agora_dispara_o_retorno(): void
    {
        $this->aplicacao();
        $nota = $this->notaDoHotel();
        EmissorFalso::responderConsultaCom(['status' => 'emitida', 'chave_acesso' => '123', 'numero' => '123']);

        $this->actingAs(User::factory()->create(['papel' => 'admin']))
            ->post(route('notas.verificar', $nota))
            ->assertRedirect(route('notas.index'));

        $this->assertSame(['nota.emitida'], array_map(fn ($r) => $r->header('X-Notas-Event')[0], $this->retornos()));
    }

    /**
     * Cancelamento pedido não é cancelamento: enquanto a prefeitura não
     * confirma, nada sai. A confirmação (aqui, pela reconciliação) é que vira
     * `nota.cancelada`.
     */
    public function test_cancelamento_so_avisa_quando_confirmado(): void
    {
        $this->aplicacao();
        $nota = $this->notaDoHotel(['status' => 'emitida', 'emitida_em' => now()]);

        $this->actingAs(User::factory()->create(['papel' => 'admin']))
            ->post(route('notas.cancelar', $nota), ['motivo' => 'Cobrança lançada em duplicidade no hotel'])
            ->assertRedirect(route('notas.index'));

        $this->assertSame([], $this->retornos());

        $nota->refresh()->forceFill(['cancelamento_solicitado_em' => now()->subHour()])->save();
        EmissorFalso::responderConsultaCom(['status' => 'cancelada']);
        $this->artisan('notas:reconciliar')->assertSuccessful();

        $this->assertSame('cancelada', $nota->fresh()->status);
        $this->assertSame(['nota.cancelada'], array_map(fn ($r) => $r->header('X-Notas-Event')[0], $this->retornos()));
    }

    public function test_cancelamento_aceito_na_hora_tambem_avisa(): void
    {
        $this->aplicacao();
        $nota = $this->notaDoHotel(['status' => 'emitida', 'emitida_em' => now()]);
        EmissorFalso::responderCancelamentoCom(['status' => 'cancelada']);

        $this->actingAs(User::factory()->create(['papel' => 'admin']))
            ->post(route('notas.cancelar', $nota), ['motivo' => 'Cobrança lançada em duplicidade no hotel']);

        $this->assertSame(['nota.cancelada'], array_map(fn ($r) => $r->header('X-Notas-Event')[0], $this->retornos()));
    }

    /** Nota manual não tem SaaS do outro lado. */
    public function test_nota_manual_nao_tem_retorno(): void
    {
        $this->aplicacao();
        $nota = Nota::factory()->create(['origem' => 'manual', 'notaas_invoice_id' => 'abc-123']);

        app(FecharNota::class)->aplicar($nota, ['status' => 'emitida', 'chave_acesso' => '123']);

        $this->assertSame([], $this->retornos());
    }

    /** Revogada a chave, revogado o retorno; o fechamento da nota segue igual. */
    public function test_aplicacao_revogada_nao_recebe_retorno(): void
    {
        $this->aplicacao(['revogada_em' => now()]);
        $nota = $this->notaDoHotel();

        app(FecharNota::class)->aplicar($nota, ['status' => 'emitida', 'chave_acesso' => '123']);

        $this->assertSame('emitida', $nota->fresh()->status);
        $this->assertSame([], $this->retornos());
    }

    /**
     * SaaS fora do ar não derruba nada: a Notaas recebe 200 (senão reenviaria
     * para sempre), a nota fecha igual, e o SaaS a alcança pelo GET.
     */
    public function test_falha_no_retorno_nunca_muda_a_nota(): void
    {
        $this->statusDoSaas = 503;
        $this->aplicacao();
        $nota = $this->notaDoHotel();

        $this->webhookDaNotaas(['event' => 'nfse.issued', 'data' => [
            'invoiceId' => 'abc-123', 'status' => 'issued', 'chNFSe' => '123',
        ]])->assertOk();

        $this->assertSame('emitida', $nota->fresh()->status);
        $this->assertCount(1, $this->retornos());
    }

    /**
     * Três tentativas com espera crescente, numa fila de verdade. O corpo
     * vai pronto no job e o segredo não: na fila `database` o job fica
     * gravado em claro.
     */
    public function test_o_retorno_e_um_job_com_tres_tentativas_e_espera_crescente(): void
    {
        Queue::fake();
        $aplicacao = $this->aplicacao();
        $nota = $this->notaDoHotel();

        app(FecharNota::class)->aplicar($nota, ['status' => 'emitida', 'chave_acesso' => '123']);

        Queue::assertPushed(EnviarRetorno::class, function (EnviarRetorno $job) use ($aplicacao) {
            $backoff = $job->backoff();

            return $job->aplicacaoId === $aplicacao->id
                && $job->evento === 'nota.emitida'
                && $job->tries === 3
                && count($backoff) === 2 && $backoff[0] < $backoff[1]
                && ! str_contains(serialize($job), self::SEGREDO);
        });
    }

    /** Redirecionamento não é entrega: seguir um 302 viraria um GET para outro lugar. */
    public function test_redirecionamento_do_saas_conta_como_falha(): void
    {
        $aplicacao = $this->aplicacao();
        $this->statusDoSaas = 302;
        $this->headersDoSaas = ['Location' => 'https://outro.test/'];

        $this->expectException(\RuntimeException::class);

        (new EnviarRetorno($aplicacao->id, 'nota.emitida', '{"event":"nota.emitida","data":{}}'))->handle();
    }
}
