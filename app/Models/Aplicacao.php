<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Um SaaS que emite pela API (spec §4.3).
 *
 * Origem e perfil moram aqui, e não no corpo da chamada: o SaaS não escolhe
 * código fiscal, e quem roubar a chave não redireciona o retorno.
 */
class Aplicacao extends Model
{
    use HasFactory;

    // O pluralizador do Laravel é inglês: sem isto ele procuraria "aplicacaos".
    protected $table = 'aplicacoes';

    protected $guarded = ['id'];

    // Nenhum dos dois segredos sai em array/JSON por acidente (log, dump).
    protected $hidden = ['chave_hash', 'segredo_callback'];

    protected function casts(): array
    {
        return [
            // Cifrado com a APP_KEY. Trocar a APP_KEY torna o segredo
            // ilegível e o retorno impossível de assinar: aí é recriar a
            // aplicação.
            'segredo_callback' => 'encrypted',
            'revogada_em' => 'datetime',
        ];
    }

    /** O que se guarda da chave de API: só o hash, nunca ela. */
    public static function hashDaChave(string $chave): string
    {
        return hash('sha256', $chave);
    }

    /**
     * A aplicação dona desta chave, se ela existir e não estiver revogada.
     *
     * Chave ausente, errada e revogada dão o mesmo `null`, e por isso a mesma
     * resposta: não damos a quem testa chaves a pista de qual delas já valeu.
     */
    public static function daChave(?string $chave): ?self
    {
        if (blank($chave)) {
            return null;
        }

        return static::ativas()->where('chave_hash', static::hashDaChave($chave))->first();
    }

    /**
     * A aplicação que recebe o retorno das notas de uma origem.
     *
     * A mais nova ativa, e não "a única": trocar a chave de um SaaS é criar a
     * aplicação nova, apontar o SaaS para ela e só então revogar a antiga.
     * Durante essa troca as duas valem, e o retorno vai para a recém-criada.
     */
    public static function daOrigem(string $origem): ?self
    {
        return static::ativas()->where('origem', $origem)->latest('id')->first();
    }

    /** @param  Builder<self>  $query */
    public function scopeAtivas(Builder $query): void
    {
        $query->whereNull('revogada_em');
    }

    public function estaRevogada(): bool
    {
        return $this->revogada_em !== null;
    }
}
