<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Não há cadastro aberto nesta plataforma: são duas pessoas, e quem entra é
 * criado por aqui.
 *
 *   php artisan usuario:criar "Nome" email@exemplo.com --papel=emissor
 *
 * A senha é sorteada e mostrada uma única vez. Não fica em log nem no banco em
 * texto claro, e não há como recuperá-la: se perder, cria-se outra.
 */
class CriarUsuarioCommand extends Command
{
    protected $signature = 'usuario:criar
        {nome : nome da pessoa}
        {email : e-mail de acesso}
        {--papel=emissor : emissor ou admin}';

    protected $description = 'Cria um usuário da plataforma com senha sorteada';

    public function handle(): int
    {
        $papel = $this->option('papel');

        if (! in_array($papel, ['admin', 'emissor'], true)) {
            $this->error("Papel inválido: {$papel}. Use admin ou emissor.");

            return self::FAILURE;
        }

        if (User::where('email', $this->argument('email'))->exists()) {
            $this->error('Já existe usuário com esse e-mail.');

            return self::FAILURE;
        }

        $senha = Str::password(16);

        User::create([
            'name' => $this->argument('nome'),
            'email' => $this->argument('email'),
            'papel' => $papel,
            'password' => Hash::make($senha),
            'email_verified_at' => now(),
        ]);

        $this->info("Usuário criado como {$papel}.");
        $this->newLine();
        $this->line("  e-mail: {$this->argument('email')}");
        $this->line("  senha:  {$senha}");
        $this->newLine();
        $this->warn('Esta senha aparece uma vez só. Anote agora.');

        return self::SUCCESS;
    }
}
