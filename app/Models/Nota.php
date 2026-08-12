<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Nota extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'emitida_em' => 'datetime',
        ];
    }

    /**
     * Os códigos fiscais que esta nota usou.
     *
     * O nome é diferente da coluna `perfil` de propósito: um método `perfil()`
     * convivendo com o atributo `perfil` confunde a leitura e faz o Eloquent
     * tentar resolver relação quando o modelo não está hidratado.
     *
     * @return array<string, mixed>
     */
    public function perfilDeServico(): array
    {
        return config("fiscal.perfis.{$this->perfil}");
    }

    /** Nota que não espera mais nada do emissor. */
    public function estaFechada(): bool
    {
        return in_array($this->status, ['emitida', 'erro', 'cancelada'], true);
    }

    public function autora()
    {
        return $this->belongsTo(User::class, 'criada_por');
    }
}
