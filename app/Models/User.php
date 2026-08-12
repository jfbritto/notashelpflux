<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'papel'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * O default vive aqui também, e não só na migração.
     *
     * O banco só aplica o dele na hora de gravar, então um User recém
     * instanciado teria `papel` nulo em memória, e qualquer código que lesse o
     * papel antes de recarregar veria nulo em vez de "emissor". Num controle
     * de acesso, ler nulo é decidir errado.
     */
    protected $attributes = ['papel' => 'emissor'];

    public function ehAdmin(): bool
    {
        return $this->papel === 'admin';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
