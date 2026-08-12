<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * A trava que faltava.
     *
     * O `docker-compose.yml` declarava `DB_DATABASE=notas` como variável do
     * contêiner. Variável do sistema é lida pelo Laravel ANTES do que o
     * `phpunit.xml` define, mesmo com `force="true"`, então a suíte inteira
     * rodou contra o banco de DESENVOLVIMENTO e o apagou a cada rodada, sem
     * uma linha de aviso. Só apareceu quando um usuário sumiu.
     *
     * Testes usam RefreshDatabase, ou seja, apagam tudo. Se o banco apontado
     * não for de teste, a suíte para antes de encostar nele.
     */
    protected function setUp(): void
    {
        // Lê a variável CRUA, e não `config()`, por dois motivos: o app ainda
        // não foi montado neste ponto, e é justamente a variável do sistema que
        // vence a do phpunit.xml. Depois de `parent::setUp()` seria tarde: o
        // RefreshDatabase já teria apagado o banco.
        $banco = $_SERVER['DB_DATABASE'] ?? $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: null;

        if ($banco !== null && ! str_contains((string) $banco, 'testing') && $banco !== ':memory:') {
            $this->fail(
                "A suíte apontaria para o banco \"{$banco}\", que não é de teste. ".
                'Os testes apagam o banco a cada rodada: rodar assim destruiria dados de verdade. '.
                'Confira DB_DATABASE no ambiente (variável de contêiner vence o phpunit.xml).'
            );
        }

        parent::setUp();
    }
}
