<?php

namespace App\Console\Commands;

use App\Models\Aplicacao;
use Illuminate\Console\Command;

/**
 * Revoga a chave de um SaaS sem apagar nada.
 *
 *   php artisan aplicacoes:revogar 1
 *
 * A partir daí a chave recebe 401 e as notas da origem deixam de gerar
 * retorno para esta aplicação. As notas continuam lá, com o histórico.
 */
class RevogarAplicacaoCommand extends Command
{
    protected $signature = 'aplicacoes:revogar {id : id da aplicação, mostrado na criação}';

    protected $description = 'Revoga a chave de API de uma aplicação, mantendo o histórico';

    public function handle(): int
    {
        $aplicacao = Aplicacao::find($this->argument('id'));

        if (! $aplicacao) {
            $this->error('Aplicação não encontrada.');

            return self::FAILURE;
        }

        if ($aplicacao->estaRevogada()) {
            $this->warn("A aplicação {$aplicacao->id} ({$aplicacao->nome}) já estava revogada desde {$aplicacao->revogada_em->format('d/m/Y H:i')}.");

            return self::SUCCESS;
        }

        $aplicacao->update(['revogada_em' => now()]);

        $this->info("Aplicação {$aplicacao->id} ({$aplicacao->nome}, origem {$aplicacao->origem}) revogada. A chave dela não vale mais.");

        return self::SUCCESS;
    }
}
