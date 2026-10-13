<?php

namespace App\Http\Middleware;

use App\Models\Aplicacao;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quem chama a API, pelo header `x-api-key` (o mesmo nome que a Notaas usa
 * conosco, e que os SaaS já sabem mandar).
 *
 * Chave ausente, errada ou revogada recebem a MESMA resposta, 401 com a
 * mesma mensagem. Respostas diferentes contariam a quem testa chaves qual
 * delas já foi válida um dia.
 *
 * A aplicação autenticada fica em `$request->attributes['aplicacao']`. É dela
 * que saem origem e perfil da nota, nunca do corpo da requisição.
 */
class AutenticarAplicacao
{
    public function handle(Request $request, Closure $next): Response
    {
        $aplicacao = Aplicacao::daChave($request->header('x-api-key'));

        if (! $aplicacao) {
            return response()->json(['message' => 'Chave de API inválida.'], 401);
        }

        $request->attributes->set('aplicacao', $aplicacao);

        return $next($request);
    }
}
