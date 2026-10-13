<?php

namespace Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Router;
use Tests\TestCase;

/**
 * O webhook da Notaas tem de estar fora do CSRF de verdade.
 *
 * Um POST nos testes não prova isso, porque o Laravel desliga o CSRF quando
 * roda testes. Por isso o teste olha a pilha de middleware que o Router monta
 * para a rota. Até 13/10/2026 a rota excluía VerifyCsrfToken, uma filha da
 * classe que o grupo `web` aplica, e a Notaas recebia 419 em produção.
 */
class RotaDoWebhookSemCsrfTest extends TestCase
{
    /** O kernel HTTP é quem registra os grupos (`web`) no Router; sem ele, a pilha vem só com os nomes. */
    private function routerComOsGrupos(): Router
    {
        $this->app->make(Kernel::class);

        return $this->app->make(Router::class);
    }

    public function test_o_webhook_da_notaas_nao_passa_pelo_csrf(): void
    {
        $router = $this->routerComOsGrupos();
        $rota = $router->getRoutes()->getByName('webhooks.notaas');

        $this->assertNotNull($rota);

        $comCsrf = collect($router->gatherRouteMiddleware($rota))
            ->filter(fn ($middleware) => is_string($middleware)
                && is_a(explode(':', $middleware)[0], PreventRequestForgery::class, true));

        $this->assertSame([], $comCsrf->values()->all(), 'O webhook da Notaas ainda passa pelo CSRF: a Notaas receberia 419.');
    }

    public function test_as_telas_continuam_com_csrf(): void
    {
        $router = $this->routerComOsGrupos();
        $rota = $router->getRoutes()->getByName('notas.store');

        $comCsrf = collect($router->gatherRouteMiddleware($rota))
            ->filter(fn ($middleware) => is_string($middleware)
                && is_a(explode(':', $middleware)[0], PreventRequestForgery::class, true));

        $this->assertNotEmpty($comCsrf->all(), 'O formulário de emissão perdeu o CSRF.');
    }
}
