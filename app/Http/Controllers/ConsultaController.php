<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Conveniência para o formulário, nunca dependência.
 *
 * Serviço externo cai, e quando cair a emissão não pode parar: estas rotas
 * respondem 204 e a tela continua editável à mão. Por isso nada aqui lança
 * exceção nem devolve erro que trave o formulário.
 */
class ConsultaController extends Controller
{
    /** Endereço e código IBGE do município a partir do CEP. */
    public function cep(string $cep): JsonResponse
    {
        $cep = preg_replace('/\D/', '', $cep);

        if (strlen($cep) !== 8) {
            return response()->json(null, 204);
        }

        try {
            $resposta = Http::timeout(4)->get("https://viacep.com.br/ws/{$cep}/json/");
            $dados = $resposta->json();

            if (! $resposta->successful() || ($dados['erro'] ?? false)) {
                return response()->json(null, 204);
            }

            return response()->json([
                'logradouro' => $dados['logradouro'] ?? null,
                'bairro' => $dados['bairro'] ?? null,
                'cidade' => $dados['localidade'] ?? null,
                'uf' => $dados['uf'] ?? null,
                'ibge' => $dados['ibge'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Consulta de CEP falhou', ['cep' => $cep, 'erro' => $e->getMessage()]);

            return response()->json(null, 204);
        }
    }

    /** Razão social e endereço a partir do CNPJ, para ela só conferir. */
    public function cnpj(string $cnpj): JsonResponse
    {
        $cnpj = preg_replace('/\D/', '', $cnpj);

        if (strlen($cnpj) !== 14) {
            return response()->json(null, 204);
        }

        try {
            $resposta = Http::timeout(5)->get("https://brasilapi.com.br/api/cnpj/v1/{$cnpj}");

            if (! $resposta->successful()) {
                return response()->json(null, 204);
            }

            $dados = $resposta->json();

            return response()->json([
                'nome' => $dados['razao_social'] ?? null,
                'logradouro' => trim(($dados['descricao_tipo_de_logradouro'] ?? '').' '.($dados['logradouro'] ?? '')) ?: null,
                'numero' => $dados['numero'] ?? null,
                'bairro' => $dados['bairro'] ?? null,
                'cidade' => $dados['municipio'] ?? null,
                'uf' => $dados['uf'] ?? null,
                'cep' => preg_replace('/\D/', '', (string) ($dados['cep'] ?? '')) ?: null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Consulta de CNPJ falhou', ['cnpj' => $cnpj, 'erro' => $e->getMessage()]);

            return response()->json(null, 204);
        }
    }
}
