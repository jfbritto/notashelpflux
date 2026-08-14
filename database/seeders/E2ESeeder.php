<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Usuário de senha conhecida para o Playwright entrar.
 *
 * Não há cadastro aberto e a senha do comando é sorteada, então sem isto o E2E
 * não teria como fazer login.
 */
class E2ESeeder extends Seeder
{
    public const EMAIL = 'emissora@e2e.test';

    public const SENHA = 'senha-de-e2e';

    public function run(): void
    {
        // Usuário com senha conhecida em produção é conta aberta. A trava vem
        // antes de qualquer coisa.
        if (! app()->environment(['local', 'testing', 'e2e'])) {
            throw new \RuntimeException('E2ESeeder não roda fora de ambiente de teste.');
        }

        User::updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Emissora E2E',
                'papel' => 'emissor',
                'password' => Hash::make(self::SENHA),
                'email_verified_at' => now(),
            ],
        );

        User::updateOrCreate(
            ['email' => 'admin@e2e.test'],
            [
                'name' => 'Admin E2E',
                'papel' => 'admin',
                'password' => Hash::make(self::SENHA),
                'email_verified_at' => now(),
            ],
        );

        // Uma nota já autorizada, para o E2E exercitar o cancelamento sem
        // depender de emitir e fechar dentro do teste.
        \App\Models\Nota::updateOrCreate(
            ['notaas_invoice_id' => 'fake-semente-emitida'],
            array_merge(\App\Models\Nota::factory()->emitida()->raw(), [
                'origem' => 'manual',
                'referencia_externa' => null,
                'tomador_nome' => 'Cliente Semente Ltda',
                'motivo_cancelamento' => null,
                'status' => 'emitida',
            ]),
        );
    }
}
