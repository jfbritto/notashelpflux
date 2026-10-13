<?php

namespace App\Console\Commands;

use App\Models\Aplicacao;
use Illuminate\Console\Command;

/**
 * Cadastra um SaaS que vai emitir pela API.
 *
 *   php artisan aplicacoes:criar "Hotel em Dia" hotelemdia software https://hotelemdia.com.br/webhooks/notas
 *
 * Gera dois segredos e mostra os dois UMA vez, como o usuario:criar faz com a
 * senha: a chave de API vira hash no banco (não há como recuperá-la), e o
 * segredo do retorno fica cifrado, legível só pela plataforma. Perdeu a
 * chave, cria-se outra aplicação e revoga-se esta.
 */
class CriarAplicacaoCommand extends Command
{
    protected $signature = 'aplicacoes:criar
        {nome : rótulo, ex.: "Hotel em Dia"}
        {origem : valor de notas.origem (ver config/fiscal.php, origens)}
        {perfil : perfil de serviço fixo da aplicação (ver config/fiscal.php, perfis)}
        {callback_url : para onde vai o retorno assinado (https)}';

    protected $description = 'Cadastra um SaaS que emite pela API e mostra a chave e o segredo uma única vez';

    public function handle(): int
    {
        $origem = (string) $this->argument('origem');
        $perfil = (string) $this->argument('perfil');
        $url = (string) $this->argument('callback_url');

        // `manual` é a emissão pela tela. Uma aplicação com essa origem
        // misturaria as notas da API com as da emissora, inclusive no que ela
        // pode ver e cancelar.
        $origens = array_diff(array_keys(config('fiscal.origens')), ['manual']);

        if (! in_array($origem, $origens, true)) {
            $this->error("Origem inválida: {$origem}. Use uma de: ".implode(', ', $origens).
                '. Origem nova entra antes em config/fiscal.php.');

            return self::FAILURE;
        }

        $dadosDoPerfil = config("fiscal.perfis.{$perfil}");

        if (! is_array($dadosDoPerfil)) {
            $this->error("Perfil inválido: {$perfil}. Use um de: ".implode(', ', array_keys(config('fiscal.perfis'))).'.');

            return self::FAILURE;
        }

        // A API não recebe código IBGE do tomador (o corpo da emissão tem
        // cidade, UF e CEP, spec §5), então só consegue emitir perfil cujo
        // local da prestação padrão é o do prestador. Perfil que pede o local
        // do tomador (nutrição) é emissão da tela.
        if (($dadosDoPerfil['local_prestacao_padrao'] ?? null) !== 'prestador') {
            $this->error("O perfil {$perfil} pede o local da prestação do tomador, que a API não recebe. Ele só se emite pela tela.");

            return self::FAILURE;
        }

        // O retorno leva dados fiscais do cliente do SaaS; em http eles
        // viajariam em claro.
        if (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            $this->error("URL de retorno inválida: {$url}. Precisa ser https.");

            return self::FAILURE;
        }

        $chave = bin2hex(random_bytes(32));
        $segredo = bin2hex(random_bytes(32));

        $aplicacao = Aplicacao::create([
            'nome' => $this->argument('nome'),
            'origem' => $origem,
            'perfil' => $perfil,
            'chave_hash' => Aplicacao::hashDaChave($chave),
            'callback_url' => $url,
            'segredo_callback' => $segredo,
        ]);

        $this->info("Aplicação {$aplicacao->id} criada: {$aplicacao->nome} ({$origem}, perfil {$perfil}).");
        $this->newLine();
        $this->line("  chave de API (header x-api-key):        {$chave}");
        $this->line("  segredo do retorno (X-Notas-Signature): {$segredo}");
        $this->newLine();
        $this->warn('Os dois aparecem uma vez só. Guarde-os agora no .env do SaaS.');

        if (Aplicacao::ativas()->where('origem', $origem)->whereKeyNot($aplicacao->id)->exists()) {
            $this->warn("Há outra aplicação ativa com a origem {$origem}. O retorno passa a ir para esta; "
                .'revogue a antiga (aplicacoes:revogar) quando o SaaS estiver usando a chave nova.');
        }

        return self::SUCCESS;
    }
}
