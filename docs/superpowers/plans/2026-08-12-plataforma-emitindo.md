# Plataforma de notas HelpFlux: emitindo e no ar

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Colocar `notas.helpflux.com.br` no ar com a emissão manual funcionando de ponta a ponta, para que a emissão de nutrição saia da plataforma em vez do portal nacional.

**Architecture:** Laravel 11 com um emissor plugável (`Emissor`), igual ao que o TreinaEdu já usa. A Notaas assina e conversa com a prefeitura; nós montamos o payload, guardamos a nota e tratamos o retorno assíncrono. A emissão termina em três lugares (envio, webhook, reconciliação) e os três seguem a mesma regra: quem fecha a nota grava o desfecho, e só na transição de estado.

**Tech Stack:** PHP 8.3, Laravel 11.31, Breeze (auth), Blade + Alpine 3 + Tailwind 3, MySQL 8, Vite 6, PHPUnit 11, Playwright 1.61.

**Fonte da verdade:** [o spec](../specs/2026-08-11-notas-helpflux-design.md). Leia as seções 3, 4 e 13 antes de começar: elas contêm decisões fiscais que erram caro e armadilhas já pagas uma vez no TreinaEdu.

**Fora deste plano:** a API para os SaaS, o retorno assinado e a virada do TreinaEdu. Isso é o plano 2, escrito depois que este estiver rodando em produção. Este plano deixa o caminho pronto (o mesmo serviço de emissão que a tela usa será o que a API vai usar), mas não constrói rota de API nenhuma.

## Convenções que valem em todas as tasks

- **Todo teste de feature usa `RefreshDatabase`.** Os trechos abaixo mostram só os métodos; a classe sempre tem `use RefreshDatabase;`. Sem isso, testes que contam linhas enxergam o que o teste anterior deixou.
- **Validação de CPF/CNPJ:** copie `app/Rules/ValidCpfCnpj.php` do TreinaEdu (`/Users/joaofilipibritto/Projetos/treinaedu/app/Rules/ValidCpfCnpj.php`) junto com o teste dele. Laravel não tem regra para documento brasileiro, e escrever dígito verificador de novo é retrabalho com chance de erro.
- **Rodar os testes:** `php artisan test` (o `phpunit.xml` já aponta para o banco de teste).

---

## Estrutura de arquivos

| arquivo | responsabilidade |
|---|---|
| `config/fiscal.php` | Perfis de serviço e qual emissor está ligado. Dado fiscal versionado, não tela. |
| `app/Models/Nota.php` | A nota. Estados e escopos. |
| `app/Services/Emissor/Emissor.php` | Contrato de duas funções: enviar e consultar. |
| `app/Services/Emissor/PayloadDaNota.php` | Monta o corpo da emissão. Isolado porque é onde moram as armadilhas fiscais e é o que mais precisa de teste. |
| `app/Services/Emissor/NotaasEmissor.php` | Fala HTTP com a Notaas. Traduz o vocabulário deles para o nosso. |
| `app/Services/Emissor/EmissorFalso.php` | Emissor de mentira para testes e E2E. Nunca toca a rede. |
| `app/Services/EmitirNota.php` | Cria a nota e manda emitir. É o ponto único de emissão: a tela usa hoje, a API vai usar amanhã. |
| `app/Services/FecharNota.php` | Aplica um desfecho vindo do emissor. Usado pelo webhook e pela reconciliação, para a regra da transição existir num lugar só. |
| `app/Http/Controllers/NotaController.php` | Tela de emissão manual e lista. |
| `app/Http/Controllers/NotaasWebhookController.php` | Retorno da Notaas, com HMAC. |
| `app/Console/Commands/ReconciliarNotasCommand.php` | Rede de segurança para webhook perdido. |
| `app/Console/Commands/CriarUsuarioCommand.php` | Não há cadastro aberto: usuário nasce por comando. |

Por que `EmitirNota` e `FecharNota` são serviços separados dos controllers: no TreinaEdu a regra de "avisar só na transição" precisou ser corrigida três vezes, em três lugares diferentes, porque estava copiada em cada caminho. Aqui ela nasce num arquivo só.

---

## Task 1: Esqueleto do projeto

**Files:**
- Create: tudo que o Laravel gera, na raiz do repositório
- Modify: `phpunit.xml`

- [ ] **Step 1: Gerar o Laravel fora do repositório e trazer para dentro**

O diretório já tem `.git`, `README.md` e `docs/`, e o `create-project` exige pasta vazia. O `--ignore-existing` protege o README e a documentação, que são o único conteúdo do repositório hoje: sem ele, o README do projeto vira o README padrão do Laravel.

```bash
cd /Users/joaofilipibritto/Projetos
composer create-project laravel/laravel /tmp/nhf-skeleton "^11.0" --no-interaction
rsync -a --ignore-existing --exclude='.git' /tmp/nhf-skeleton/ notashelpflux/
rm -rf /tmp/nhf-skeleton
```

Conferir depois: `head -3 notashelpflux/README.md` deve mostrar o título da plataforma, não "About Laravel".

- [ ] **Step 2: Instalar Breeze (Blade) e Tailwind**

```bash
cd /Users/joaofilipibritto/Projetos/notashelpflux
composer require laravel/breeze --dev --no-interaction
php artisan breeze:install blade --no-interaction
npm install && npm run build
```

- [ ] **Step 3: Criar os bancos (dev e teste)**

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS notashelpflux; CREATE DATABASE IF NOT EXISTS notashelpflux_testing;"
```

- [ ] **Step 4: Apontar o `.env` e o `phpunit.xml` para eles**

No `.env`: `DB_DATABASE=notashelpflux`, `APP_NAME="Notas HelpFlux"`, `APP_URL=http://localhost:8000`.

No `phpunit.xml`, dentro de `<php>`, espelhando o TreinaEdu:

```xml
<env name="DB_CONNECTION" value="mysql" force="true"/>
<env name="DB_DATABASE" value="notashelpflux_testing" force="true"/>
<env name="BCRYPT_ROUNDS" value="4"/>
<env name="CACHE_STORE" value="array"/>
<env name="MAIL_MAILER" value="array"/>
<env name="QUEUE_CONNECTION" value="sync"/>
<env name="SESSION_DRIVER" value="array"/>
```

- [ ] **Step 5: Rodar a suíte que veio de fábrica**

Run: `php artisan test`
Expected: PASS (os testes do Breeze).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "chore: esqueleto Laravel 11 com Breeze e Tailwind"
```

---

## Task 2: Perfis de serviço

O que diferencia uma nota de software de uma de nutrição são quatro códigos fiscais. Eles ficam versionados porque mudam raramente e erram caro (spec §4.2).

**Files:**
- Create: `config/fiscal.php`
- Test: `tests/Feature/PerfilDeServicoTest.php`

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class PerfilDeServicoTest extends TestCase
{
    public function test_o_perfil_de_nutricao_carrega_os_codigos_da_nota_real(): void
    {
        $perfil = config('fiscal.perfis.nutricao');

        // Conferidos contra a DANFSe de 06/08/2026 emitida pelo portal nacional.
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
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter=PerfilDeServicoTest`
Expected: FAIL, `config('fiscal.perfis.nutricao')` é null.

- [ ] **Step 3: Criar `config/fiscal.php`**

```php
<?php

return [
    /*
     * Emissor ligado: 'notaas' em produção, 'fake' em teste e E2E.
     *
     * Teste que emite nota de verdade é problema fiscal, não bug. O fake é
     * forçado no phpunit.xml e no ambiente de E2E, e a chave real nunca entra
     * nesses ambientes.
     */
    'emissor' => env('FISCAL_EMISSOR', 'notaas'),

    'notaas' => [
        'api_key' => env('NOTAAS_API_KEY'),
        'base_url' => env('NOTAAS_BASE_URL', 'https://platform.notaas.com.br/api/v1'),
        'webhook_secret' => env('NOTAAS_WEBHOOK_SECRET'),
    ],

    /*
     * Emitente: HELPFLUX SOLUCOES EM TECNOLOGIA LTDA.
     *
     * O CNPJ e a inscrição saem impressos em toda nota, não são segredo. O
     * certificado A1 não está aqui: ele vive na conta da Notaas, que assina.
     */
    'prestador' => [
        'cnpj' => env('FISCAL_CNPJ', '58063432000121'),
        'codigo_municipio' => env('FISCAL_IBGE', '3204559'), // Santa Maria de Jetibá-ES
    ],

    /*
     * Perfis de serviço. Os códigos de nutrição foram conferidos contra uma
     * DANFSe real de 06/08/2026; os de software são os que o TreinaEdu já
     * emite há meses.
     *
     * `local_prestacao_padrao` diz só o que a TELA SUGERE. A nota grava o
     * município escolhido, porque local da prestação e município de incidência
     * do ISS são campos diferentes e divergem (spec §3).
     */
    'perfis' => [
        'software' => [
            'rotulo' => 'Licenciamento de software',
            'item_lista_servico' => '1.05',
            'codigo_tributacao_nacional' => '010501',
            'nbs' => null,
            // :produto é trocado por quem emite (TreinaEdu, HelpDiet). Só
            // passa a ser usado no plano 2; na fase 1 nenhuma nota de software
            // é emitida por aqui.
            'descricao_padrao' => 'Mensalidade referente ao uso da plataforma :produto',
            'local_prestacao_padrao' => 'prestador',
            'aliquota' => 2.01,
        ],
        'nutricao' => [
            'rotulo' => 'Atendimento nutricional',
            'item_lista_servico' => '4.10',
            'codigo_tributacao_nacional' => '041001',
            'nbs' => '1.2301.99.00',
            'descricao_padrao' => 'Atendimentos nutricionais',
            'local_prestacao_padrao' => 'tomador',
            'aliquota' => 2.01,
        ],
    ],
];
```

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --filter=PerfilDeServicoTest`
Expected: PASS

- [ ] **Step 5: Forçar o emissor falso nos testes**

No `phpunit.xml`, junto dos outros env: `<env name="FISCAL_EMISSOR" value="fake" force="true"/>`

- [ ] **Step 6: Commit**

```bash
git add config/fiscal.php phpunit.xml tests/Feature/PerfilDeServicoTest.php
git commit -m "feat(fiscal): perfis de servico versionados, conferidos contra nota real"
```

---

## Task 3: A tabela de notas

**Files:**
- Create: `database/migrations/XXXX_create_notas_table.php`
- Create: `app/Models/Nota.php`
- Create: `database/factories/NotaFactory.php`
- Test: `tests/Feature/NotaTest.php`

- [ ] **Step 1: Escrever o teste que falha**

```php
use Illuminate\Foundation\Testing\RefreshDatabase;
// ...
use RefreshDatabase;

public function test_a_mesma_referencia_da_mesma_origem_nao_entra_duas_vezes(): void
{
    Nota::factory()->create(['origem' => 'treinaedu', 'referencia_externa' => 'inv-1']);

    $this->expectException(\Illuminate\Database\QueryException::class);

    Nota::factory()->create(['origem' => 'treinaedu', 'referencia_externa' => 'inv-1']);
}

/**
 * Nota manual não tem referência de origem. O MySQL trata nulos como
 * distintos, então o índice único não atrapalha a emissão avulsa: sem isso,
 * a segunda nota manual seria recusada pelo banco.
 */
public function test_varias_notas_manuais_convivem_sem_referencia(): void
{
    Nota::factory()->count(3)->create(['origem' => 'manual', 'referencia_externa' => null]);

    $this->assertSame(3, Nota::count());
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter=NotaTest`
Expected: FAIL, tabela `notas` não existe.

- [ ] **Step 3: Criar a migração**

```bash
php artisan make:migration create_notas_table
```

```php
Schema::create('notas', function (Blueprint $table) {
    $table->id();

    $table->string('origem', 20);                        // treinaedu | helpdiet | manual
    $table->string('referencia_externa')->nullable();    // a referência do SaaS; nula na manual
    $table->string('perfil', 20);                        // software | nutricao

    $table->string('tomador_tipo', 2);                   // pf | pj
    $table->string('tomador_documento', 14);
    $table->string('tomador_nome');
    $table->string('tomador_email')->nullable();
    $table->string('tomador_cep', 8)->nullable();
    $table->string('tomador_logradouro')->nullable();
    $table->string('tomador_numero', 20)->nullable();
    $table->string('tomador_bairro')->nullable();
    $table->string('tomador_cidade')->nullable();
    $table->string('tomador_uf', 2)->nullable();
    $table->string('tomador_ibge', 7)->nullable();

    // Onde o serviço foi prestado. NÃO é o município do prestador: numa nota
    // de nutrição real o atendimento foi em Vitória e o ISS ficou em Santa
    // Maria de Jetibá (spec §3).
    $table->string('local_prestacao_ibge', 7);
    $table->string('local_prestacao_nome');

    $table->text('descricao');
    $table->decimal('valor', 10, 2);
    $table->string('competencia', 7);                    // AAAA-MM

    $table->string('status', 20)->default('processando'); // processando | emitida | erro | cancelada
    $table->string('notaas_invoice_id')->nullable()->unique();
    $table->string('chave_acesso', 60)->nullable();
    $table->string('numero', 60)->nullable();
    $table->string('pdf_url')->nullable();
    $table->string('xml_url')->nullable();
    $table->text('erro')->nullable();

    $table->timestamp('emitida_em')->nullable();
    $table->foreignId('criada_por')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();

    // Idempotência sem código: o mesmo pagamento reenviado não vira duas notas.
    $table->unique(['origem', 'referencia_externa']);
    $table->index(['status', 'created_at']);
});
```

`notaas_invoice_id` é único porque um id do emissor é uma nota. No TreinaEdu a falta desse índice deixou passar uma duplicata que só apareceu na tela do cliente.

- [ ] **Step 4: Criar o model e a factory**

`app/Models/Nota.php`:

```php
class Nota extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['valor' => 'decimal:2', 'emitida_em' => 'datetime'];
    }

    // Nome diferente da coluna `perfil` de propósito: um método `perfil()`
    // convivendo com o atributo `perfil` confunde leitura e faz o Eloquent
    // tentar resolver relação quando o modelo não está hidratado.
    public function perfilDeServico(): array
    {
        return config("fiscal.perfis.{$this->perfil}");
    }

    public function estaFechada(): bool
    {
        return in_array($this->status, ['emitida', 'erro', 'cancelada'], true);
    }
}
```

- [ ] **Step 5: Rodar e ver passar**

Run: `php artisan test --filter=NotaTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add database/ app/Models/Nota.php tests/Feature/NotaTest.php
git commit -m "feat(notas): tabela e model, com idempotencia por origem e referencia"
```

---

## Task 4: O contrato do emissor e o emissor falso

**Files:**
- Create: `app/Services/Emissor/Emissor.php`
- Create: `app/Services/Emissor/EmissorFalso.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/EmissorFalsoTest.php`

- [ ] **Step 1: Escrever o teste que falha**

```php
public function test_em_teste_o_emissor_ligado_e_o_falso(): void
{
    $this->assertInstanceOf(EmissorFalso::class, app(Emissor::class));
}

/**
 * A trava que importa mais que qualquer outra neste projeto: em ambiente de
 * teste, nenhum caminho pode chegar na Notaas de verdade.
 */
public function test_o_emissor_falso_nao_toca_a_rede(): void
{
    Http::preventStrayRequests();

    $nota = Nota::factory()->create();

    $this->assertSame('processando', app(Emissor::class)->enviar($nota)['status']);
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter=EmissorFalsoTest`
Expected: FAIL, interface não existe.

- [ ] **Step 3: Escrever o contrato**

```php
<?php

namespace App\Services\Emissor;

use App\Models\Nota;

/**
 * Quem fala com o mundo fiscal. Duas funções, porque a emissão é assíncrona:
 * manda-se a nota e pergunta-se depois o que aconteceu com ela.
 *
 * O retorno é sempre o mesmo formato, no NOSSO vocabulário, para que o resto
 * do sistema não precise conhecer o dialeto de nenhum emissor:
 *
 *   ['status' => 'processando'|'emitida'|'erro'|'cancelada',
 *    'numero' => ?string, 'chave_acesso' => ?string,
 *    'pdf_url' => ?string, 'xml_url' => ?string, 'erro' => ?string]
 *
 * CONTRATO ADICIONAL, e ele não é opcional: `enviar()` grava
 * `notas.notaas_invoice_id` com o id que o emissor devolveu. Esse id é o
 * ÚNICO elo entre a nossa linha e a nota do emissor. Sem ele o webhook chega
 * e não há como saber de que nota ele fala, e a reconciliação não tem o que
 * consultar. Vale para as duas implementações, inclusive a falsa.
 */
interface Emissor
{
    /** @return array<string, mixed> */
    public function enviar(Nota $nota): array;

    /** @return array<string, mixed> */
    public function consultar(string $idNoEmissor): array;
}
```

- [ ] **Step 4: Escrever o emissor falso**

Ele aceita tudo, grava um id de mentira (`'fake-'.$nota->id`) e devolve "processando", como o real. Quem quiser simular o desfecho usa `EmissorFalso::responderConsultaCom([...])` no teste.

Ele também tem uma trava: `EmissorFalso::proibirEnvio()` faz `enviar()` lançar exceção. Serve para provar, na task 13, que a reconciliação **consulta e nunca emite**. Sem isso, aquele teste passaria por coincidência (ninguém chamou `enviar()`) em vez de por garantia (chamar seria erro).

O teste do id faz parte desta task, senão as tasks 8 e 13 quebram sem motivo aparente:

```php
public function test_o_emissor_falso_tambem_grava_o_id(): void
{
    $nota = Nota::factory()->create();

    app(Emissor::class)->enviar($nota);

    $this->assertNotNull($nota->fresh()->notaas_invoice_id);
}
```

- [ ] **Step 5: Ligar no container**

Em `AppServiceProvider::register`:

```php
$this->app->bind(Emissor::class, fn () => match (config('fiscal.emissor')) {
    'fake' => new EmissorFalso(),
    default => new NotaasEmissor(),
});
```

- [ ] **Step 6: Rodar e ver passar**

Run: `php artisan test --filter=EmissorFalsoTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git commit -am "feat(emissor): contrato plugavel e emissor falso ligado nos testes"
```

---

## Task 5: O payload da emissão

Esta é a task onde os erros custam dinheiro e tempo de prefeitura. Cada regra abaixo foi paga uma vez no TreinaEdu (spec §13).

**Files:**
- Create: `app/Services/Emissor/PayloadDaNota.php`
- Test: `tests/Feature/PayloadDaNotaTest.php`

- [ ] **Step 1: Escrever os testes que falham**

```php
/**
 * O prestador NÃO vai no corpo: ele é o dono da chave de API. Mandar a
 * inscrição municipal dele fez a Notaas recusar com E0120.
 */
public function test_o_prestador_nao_viaja_no_corpo(): void
{
    $payload = (new PayloadDaNota)->montar(Nota::factory()->create());

    $this->assertArrayNotHasKey('prestador', $payload);
    $this->assertStringNotContainsString('58063432000121', json_encode($payload));
}

/**
 * A Notaas recusa antes de chegar na prefeitura: "cidade e uf são
 * obrigatórios quando endereço é informado". O código IBGE sozinho não basta.
 */
public function test_o_endereco_do_tomador_leva_cidade_e_uf_junto_do_ibge(): void
{
    $nota = Nota::factory()->create([
        'tomador_cidade' => 'Vitória', 'tomador_uf' => 'ES', 'tomador_ibge' => '3205309',
    ]);

    $endereco = (new PayloadDaNota)->montar($nota)['tomador']['endereco'];

    $this->assertSame('Vitória', $endereco['cidade']);
    $this->assertSame('ES', $endereco['uf']);
    $this->assertSame('3205309', $endereco['codigoMunicipio']);
}

/**
 * O motivo de o local da prestação ser campo da nota. Atendimento em Vitória,
 * ISS devido em Santa Maria de Jetibá: são dois municípios na mesma nota, e o
 * que vai no serviço é o do ATENDIMENTO.
 */
public function test_o_local_da_prestacao_vai_no_servico_e_pode_diferir_do_prestador(): void
{
    $nota = Nota::factory()->create([
        'perfil' => 'nutricao', 'local_prestacao_ibge' => '3205309',
    ]);

    $payload = (new PayloadDaNota)->montar($nota);

    $this->assertSame('3205309', $payload['servico']['codigoMunicipio']);
    $this->assertNotSame(config('fiscal.prestador.codigo_municipio'), $payload['servico']['codigoMunicipio']);
}

public function test_a_nota_de_nutricao_leva_os_codigos_do_perfil(): void
{
    $nota = Nota::factory()->create(['perfil' => 'nutricao']);

    $servico = (new PayloadDaNota)->montar($nota)['servico'];

    $this->assertSame('041001', $servico['codigo']);
    $this->assertSame('4.10', $servico['itemListaServico']);
}

public function test_pessoa_fisica_vai_como_cpf_e_juridica_como_cnpj(): void
{
    $pf = (new PayloadDaNota)->montar(Nota::factory()->create([
        'tomador_tipo' => 'pf', 'tomador_documento' => '52998224725',
    ]));
    $pj = (new PayloadDaNota)->montar(Nota::factory()->create([
        'tomador_tipo' => 'pj', 'tomador_documento' => '11222333000181',
    ]));

    $this->assertSame('52998224725', $pf['tomador']['cpf']);
    $this->assertArrayNotHasKey('cnpj', $pf['tomador']);
    $this->assertSame('11222333000181', $pj['tomador']['cnpj']);
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter=PayloadDaNotaTest`
Expected: FAIL, classe não existe.

- [ ] **Step 3: Escrever `PayloadDaNota`**

Monta `referencia`, `competencia`, `tomador` (com o endereço completo) e `servico` (código, item, descrição, `codigoMunicipio` = **local da prestação**) e `valores`. Sem `prestador`.

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --filter=PayloadDaNotaTest`
Expected: PASS (5 testes)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Emissor/PayloadDaNota.php tests/Feature/PayloadDaNotaTest.php
git commit -m "feat(emissor): payload da nota, com as tres armadilhas do padrao nacional"
```

---

## Task 6: O emissor da Notaas

**Files:**
- Create: `app/Services/Emissor/NotaasEmissor.php`
- Test: `tests/Feature/NotaasEmissorTest.php`

- [ ] **Step 1: Escrever os testes que falham**

Com `Http::fake`, cobrindo:

```php
public function test_o_id_do_emissor_e_guardado_na_hora_do_envio(): void
{
    Http::fake(['*/emitir' => Http::response(['invoiceId' => 'abc-123', 'status' => 'queued'], 202)]);
    $nota = Nota::factory()->create();

    $retorno = (new NotaasEmissor)->enviar($nota);

    $this->assertSame('processando', $retorno['status']);
    $this->assertSame('abc-123', $nota->fresh()->notaas_invoice_id);
}

/**
 * Sem o id guardado, o webhook chega e não há como saber de que nota ele
 * fala. É o único elo entre a nossa linha e a nota deles.
 */
public function test_falha_no_envio_devolve_erro_com_a_mensagem_do_emissor(): void
{
    Http::fake(['*/emitir' => Http::response(['message' => 'E0120 inscricao do prestador'], 400)]);

    $retorno = (new NotaasEmissor)->enviar(Nota::factory()->create());

    $this->assertSame('erro', $retorno['status']);
    $this->assertStringContainsString('E0120', $retorno['erro']);
}

/**
 * No padrão nacional o número É a chave de acesso, 50 dígitos, e a Notaas
 * devolve a mesma chave em dois campos, num deles com o prefixo "NFS". Gravar
 * o prefixado fez a tela de um cliente mostrar "NFS32045592258...".
 */
public function test_o_prefixo_nfs_e_removido_do_numero_e_da_chave(): void
{
    Http::fake(['*/invoices/*/status' => Http::response([
        'status' => 'issued',
        'chNFSe' => '32045592258063432000121000000000000126089909776545',
        'nNFSe' => 'NFS32045592258063432000121000000000000126089909776545',
        'pdfUrl' => 'https://storage.notaas.test/n.pdf',
    ], 200)]);

    $retorno = (new NotaasEmissor)->consultar('abc-123');

    $this->assertSame('emitida', $retorno['status']);
    $this->assertStringStartsNotWith('NFS', $retorno['numero']);
    $this->assertSame('32045592258063432000121000000000000126089909776545', $retorno['chave_acesso']);
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter=NotaasEmissorTest`
Expected: FAIL

- [ ] **Step 3: Escrever `NotaasEmissor`**

`POST /emitir` com header `x-api-key`, guardando `invoiceId`. `GET /invoices/{id}/status` para consultar. Um método privado `traduzir()` mapeando `issued|authorized → emitida`, `error|rejected → erro`, `cancelled|canceled → cancelada`, `queued|processing → processando`. Um `semPrefixo()` tirando `NFS`. Em caso de erro, `Log::error` com o payload junto (não há segredo nele: o prestador não viaja no corpo).

A chave e o número saem de três campos possíveis, na ordem `chNFSe`, `nNFSe`, `numeroNfe`, como no driver do TreinaEdu: a Notaas já usou nomes diferentes, e cair no terceiro é mais barato que descobrir em produção que o número chegou nulo.

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --filter=NotaasEmissorTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git commit -am "feat(emissor): Notaas, com traducao de status e limpeza do prefixo NFS"
```

---

## Task 7: Emitir e fechar a nota

Os dois serviços que concentram regra. `EmitirNota` é o ponto único de emissão; `FecharNota` é o ponto único de desfecho.

**Files:**
- Create: `app/Services/EmitirNota.php`
- Create: `app/Services/FecharNota.php`
- Test: `tests/Feature/EmitirNotaTest.php`, `tests/Feature/FecharNotaTest.php`

- [ ] **Step 1: Escrever os testes que falham**

```php
public function test_emitir_grava_a_nota_como_processando_e_manda_para_o_emissor(): void

public function test_a_mesma_referencia_da_mesma_origem_devolve_a_nota_existente_sem_reenviar(): void

/**
 * A regra que precisou ser corrigida três vezes no TreinaEdu, cada vez num
 * caminho diferente. Aqui ela mora num arquivo só.
 */
public function test_fechar_uma_nota_ja_fechada_no_mesmo_estado_nao_faz_nada(): void

public function test_documentos_prontos_anexam_links_sem_mexer_no_status(): void
```

- [ ] **Step 2 a 4: falhar, implementar, passar**

`FecharNota::aplicar(Nota $nota, array $retorno): bool` devolve se houve transição. Links de documento (`pdf_url`/`xml_url`) são aplicados sempre; status só muda para frente.

- [ ] **Step 5: Commit**

```bash
git commit -am "feat(notas): emissao e desfecho como servicos, com a regra da transicao num lugar so"
```

---

## Task 8: O webhook da Notaas

**Files:**
- Create: `app/Http/Controllers/NotaasWebhookController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/NotaasWebhookTest.php`

- [ ] **Step 1: Escrever os testes que falham**

```php
public function test_assinatura_invalida_e_recusada_com_401(): void

/**
 * Sem segredo configurado a verificação é dispensada, que é o estado do
 * primeiro dia, antes de cadastrar o endpoint no painel da Notaas.
 */
public function test_sem_segredo_configurado_o_webhook_passa(): void

public function test_o_evento_de_emissao_fecha_a_nota_pelo_id_do_emissor(): void

/**
 * `nfse.documents_ready` chega DEPOIS da emissão, às vezes duas vezes (XML
 * primeiro, PDF depois). Só anexa links.
 */
public function test_documents_ready_anexa_links_e_nao_mexe_no_status(): void

public function test_o_tipo_do_evento_e_aceito_no_corpo_ou_no_header(): void
```

- [ ] **Step 2 a 4: falhar, implementar, passar**

HMAC-SHA256 do corpo cru contra `X-Notaas-Signature`, com `hash_equals`. Evento do corpo ou do header `X-Notaas-Event`. Acha a nota por `notaas_invoice_id`, com `referencia` como plano B. Responde 200 sempre que a requisição é legítima, para o emissor não reenfileirar entrega de nota que não conhecemos.

Rota fora do CSRF:

```php
Route::post('/webhooks/notaas', [NotaasWebhookController::class, 'handle'])
    ->name('webhooks.notaas')
    ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);
```

**Não troque `VerifyCsrfToken` por `ValidateCsrfToken` achando que está consertando.** Parece errado porque o grupo `web` do Laravel 11 aplica `ValidateCsrfToken`, mas a exclusão funciona: `Router::resolveMiddleware` usa `isSubclassOf` (`Illuminate/Routing/Router.php:858`) e `ValidateCsrfToken extends VerifyCsrfToken`. É a mesma linha que segura quatro webhooks do TreinaEdu em produção há meses. O teste de assinatura inválida desta task prova isso de qualquer forma: se o CSRF estivesse ativo, a resposta seria 419 e não 401.

- [ ] **Step 5: Commit**

```bash
git commit -am "feat(webhook): retorno da Notaas assinado, com os quatro eventos"
```

---

## Task 9: Usuários e papéis

Sem cadastro aberto: dois usuários, criados por comando (spec §7).

**Files:**
- Create: `database/migrations/XXXX_add_papel_to_users_table.php`
- Create: `app/Console/Commands/CriarUsuarioCommand.php`
- Modify: `routes/auth.php` (remover registro público)
- Test: `tests/Feature/AcessoTest.php`

- [ ] **Step 1: Escrever os testes que falham**

Só sobre acesso. A lista de notas ainda não existe (é a task 11), e o teste de escopo por papel mora lá.

```php
public function test_nao_existe_cadastro_publico(): void
{
    $this->get('/register')->assertNotFound();
    $this->post('/register', [])->assertNotFound();
}

public function test_usuario_criado_por_comando_consegue_entrar(): void
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter=AcessoTest`
Expected: FAIL, `/register` responde 200.

- [ ] **Step 3: Implementar**

Coluna `papel` (`admin` | `emissor`), default `emissor`. Tirar as rotas de registro de `routes/auth.php`. Comando:

```bash
php artisan usuario:criar "Nome" email@exemplo.com --papel=emissor
```

Ele sorteia a senha, imprime uma vez e não guarda em lugar nenhum.

- [ ] **Step 4: Apagar o teste de registro que o Breeze trouxe**

```bash
rm tests/Feature/Auth/RegistrationTest.php
```

Ele afirma que existe cadastro público, que é exatamente o que esta task remove. Deixá-lo ali quebra a suíte que a task 1 deixou verde, por um motivo que não é defeito: é decisão de produto (spec §7).

- [ ] **Step 5: Rodar a suíte inteira e ver passar**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git commit -am "feat(acesso): papeis admin e emissor, sem cadastro publico"
```

---

## Task 10: A tela de emissão

**Files:**
- Create: `app/Http/Controllers/NotaController.php`
- Create: `app/Http/Requests/EmitirNotaRequest.php`
- Create: `resources/views/notas/index.blade.php`, `resources/views/notas/create.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/EmissaoManualTest.php`

- [ ] **Step 1: Escrever os testes que falham**

```php
public function test_emitir_pela_tela_cria_a_nota_com_o_perfil_de_nutricao(): void

/**
 * Nenhum código fiscal é digitado. Se um dia a tela passar a aceitar
 * `perfil` ou `codigo_tributacao` do formulário, este teste quebra.
 */
public function test_a_tela_nao_aceita_codigo_fiscal_vindo_do_formulario(): void

public function test_o_local_do_atendimento_vira_o_local_da_prestacao(): void

public function test_valor_zerado_e_documento_invalido_sao_recusados(): void
```

- [ ] **Step 2 a 4: falhar, implementar, passar**

O formulário tem: cliente (CPF/CNPJ, nome, e-mail), endereço (CEP preenche o resto), "onde o atendimento foi feito" (sugerindo a cidade do cliente), valor e descrição (vinda do perfil, editável). O perfil vem fixo do controller, nunca do request.

Nesta task o campo de CEP ainda **não preenche nada**: a busca é a task 12. Os campos de endereço são digitáveis desde já, e nenhum teste daqui cobre a busca. É comportamento esperado, não defeito, e a tela funciona inteira sem ela.

- [ ] **Step 5: Commit**

```bash
git commit -am "feat(tela): emissao manual sem jargao fiscal"
```

---

## Task 11: Lista de notas e repetir nota

**Files:**
- Modify: `app/Http/Controllers/NotaController.php`, `resources/views/notas/index.blade.php`
- Test: `tests/Feature/ListaDeNotasTest.php`

- [ ] **Step 1: Escrever os testes que falham**

```php
public function test_a_lista_mostra_situacao_e_os_links_de_pdf_e_xml(): void

public function test_visitante_nao_ve_a_lista_de_notas(): void

public function test_emissor_ve_so_as_notas_manuais_e_admin_ve_todas(): void

/**
 * As quatro situações aparecem como são. Nota recusada não pode se passar por
 * pendente: no TreinaEdu isso escondeu uma nota parada por um dia.
 */
public function test_nota_com_erro_nao_aparece_como_em_emissao(): void

public function test_repetir_nota_abre_o_formulario_com_o_cliente_preenchido(): void
```

- [ ] **Step 2 a 5: falhar, implementar, passar, commit**

---

## Task 12: Busca por CNPJ e por CEP

Conveniência, não dependência: se o serviço externo cair, ela digita.

**Files:**
- Create: `app/Http/Controllers/ConsultaController.php`
- Test: `tests/Feature/ConsultaExternaTest.php`

- [ ] **Step 1: Escrever os testes que falham**

```php
public function test_cnpj_traz_razao_social_e_endereco(): void

public function test_servico_fora_do_ar_devolve_204_e_a_tela_segue_editavel(): void
```

- [ ] **Step 2 a 5: falhar, implementar, passar, commit**

BrasilAPI para CNPJ (`https://brasilapi.com.br/api/cnpj/v1/{cnpj}`) e ViaCEP para CEP, os dois com `timeout(4)` e `try/catch`.

---

## Task 13: Reconciliação de notas paradas

A emissão termina no webhook, e webhook se perde. Sem isto, nota aceita pelo emissor fica em "processando" para sempre. Aconteceu no TreinaEdu em 11/08/2026.

**Files:**
- Create: `app/Console/Commands/ReconciliarNotasCommand.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/ReconciliacaoTest.php`

- [ ] **Step 1: Escrever os testes que falham**

```php
public function test_nota_que_saiu_no_emissor_e_fechada(): void

/**
 * A janela existe para não competir com o webhook, que é quem fecha a nota no
 * caminho normal.
 */
public function test_nota_recem_enviada_nao_e_consultada(): void

/**
 * CONSULTA, nunca reemite. Reenviar uma nota que o emissor já autorizou
 * geraria uma segunda NFS-e do mesmo pagamento.
 */
public function test_a_reconciliacao_nunca_emite(): void
{
    EmissorFalso::proibirEnvio(); // enviar() passa a lançar exceção (task 4)

    $this->artisan('notas:reconciliar')->assertSuccessful();
}
```

- [ ] **Step 2 a 4: falhar, implementar, passar**

```php
Schedule::command('notas:reconciliar')->hourly();
```

- [ ] **Step 5: Commit**

```bash
git commit -am "feat(notas): reconciliacao de nota parada em processamento"
```

---

## Task 14: E2E com Playwright

**Files:**
- Create: `playwright.config.ts`, `e2e/emissao-manual.spec.ts`, `e2e/helpers/`
- Create: `.env.e2e`

- [ ] **Step 1: Instalar e configurar**

```bash
npm install -D @playwright/test
npx playwright install chromium
```

O `webServer` do Playwright sobe o app com o emissor falso. As duas variáveis vão **no bloco `env` do próprio `webServer`**, não só no `.env.e2e`: um arquivo de ambiente só é lido quando `APP_ENV` já vale `e2e`, e a garantia mais importante deste projeto não pode depender dessa ordem.

```ts
webServer: {
  command: 'php artisan serve --port=8300',
  url: 'http://127.0.0.1:8300',
  env: { APP_ENV: 'e2e', FISCAL_EMISSOR: 'fake', NOTAAS_API_KEY: '' },
  reuseExistingServer: !process.env.CI,
},
```

**A chave real da Notaas nunca entra no ambiente de E2E**, e por isso ela vai explicitamente vazia acima: se algum caminho escapar do emissor falso, ele falha por falta de chave em vez de emitir uma nota de verdade.

- [ ] **Step 2: Resolver o login antes dos specs**

Não há cadastro público e a senha do comando é sorteada, então o Playwright não teria como entrar. Criar `database/seeders/E2ESeeder.php` com um usuário de senha conhecida e rodá-lo no `globalSetup`:

```php
User::updateOrCreate(
    ['email' => 'emissor@e2e.test'],
    ['name' => 'Emissor E2E', 'papel' => 'emissor', 'password' => Hash::make('senha-e2e')],
);
```

O seeder tem uma trava na primeira linha, porque usuário com senha conhecida em produção é conta aberta:

```php
if (! app()->environment(['local', 'testing', 'e2e'])) {
    throw new \RuntimeException('E2ESeeder não roda fora de teste.');
}
```

- [ ] **Step 3: Escrever os specs**

```ts
test('emite uma nota de nutrição e ela aparece na lista', ...)
test('repetir nota abre o formulário com o cliente preenchido', ...)
test('o CEP preenche cidade, UF e sugere o local do atendimento', ...)
```

- [ ] **Step 4: Rodar**

Run: `npx playwright test`
Expected: 3 passed

- [ ] **Step 5: Commit**

```bash
git commit -am "test(e2e): emissao manual de ponta a ponta, sem tocar o emissor real"
```

---

## Task 15: No ar

**Files:**
- Create: `.github/workflows/deploy.yml`
- Create: `deploy.sh` (referência do que vai para `/home/deploy/deploy-notas.sh` na VPS)
- Create: `docs/deploy.md`

- [ ] **Step 1: Espelhar o workflow do TreinaEdu**

Dois jobs: `test` (MySQL de serviço, PHP 8.3, `php artisan test --stop-on-failure`) e `deploy` (SSH, `concurrency: group deploy-notas, cancel-in-progress: false`). O deploy só roda se os testes passarem.

- [ ] **Step 2: Preparar a VPS**

nginx para `notas.helpflux.com.br`, php-fpm, banco `notashelpflux`, certificado por Let's Encrypt, e o `.env` de produção com `FISCAL_EMISSOR=notaas` e a chave da Notaas.

**Conferir antes:** o plano gratuito da Notaas é de 50 notas por mês, e a partir daqui somam-se as origens (spec §12).

- [ ] **Step 3: Primeira emissão real, conferida campo a campo**

Emitir uma nota de nutrição de verdade e comparar com a DANFSe de 06/08/2026: código de tributação, item da lista, NBS, local da prestação e município de incidência do ISS. **É o único jeito de saber que o perfil está certo**, porque nota errada só aparece depois de emitida.

- [ ] **Step 4: Cadastrar o webhook no painel da Notaas**

Apontando para `https://notas.helpflux.com.br/webhooks/notaas`, com o segredo no `.env`.

**Atenção:** enquanto o TreinaEdu ainda emite direto, a conta da Notaas tem um webhook só. Apontá-lo para cá faz a nota do TreinaEdu parar de fechar sozinha. Ou se cria um segundo webhook no painel (se a Notaas permitir), ou este passo espera a virada do plano 2.

- [ ] **Step 5: Commit**

```bash
git commit -am "chore(deploy): workflow e documentacao de producao"
```

---

## Depois deste plano

O plano 2 cobre a API para os SaaS (`POST /api/notas` com chave por aplicação), o retorno assinado com os quatro eventos, o `PlataformaNfseDriver` no TreinaEdu e a virada, que precisa ser um ato só: webhook da Notaas e `NFSE_DRIVER` mudam na mesma janela, com a fila vazia.
