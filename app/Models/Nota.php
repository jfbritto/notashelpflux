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

    /**
     * O CPF ou CNPJ do tomador com a máscara, para a tela. A coluna guarda só
     * os dígitos; o que não tiver 11 nem 14 sai como está.
     */
    public function documentoDoTomador(): string
    {
        $d = (string) $this->tomador_documento;

        return match (strlen($d)) {
            11 => preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $d),
            14 => preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $d),
            default => $d,
        };
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
