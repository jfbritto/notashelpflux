<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
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
    /** Em ordem: o primeiro que responder com a razão social vale. */
    private const PROVEDORES_DE_CNPJ = [
        'https://brasilapi.com.br/api/cnpj/v1/',
        'https://minhareceita.org/',
    ];

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

    /**
     * Razão social e endereço a partir do CNPJ, para ela só conferir.
     *
     * Dois provedores, na ordem: o BrasilAPI responde 429 com frequência
     * (medido no TreinaEdu em 21/10/2026), e o minhareceita.org devolve a
     * mesma base no mesmo formato, então entra de reserva sem tradução. Um dia
     * de cache por CNPJ: estes dados quase não mudam, e o cliente recorrente
     * não gasta a cota de ninguém. Falha não entra no cache.
     */
    public function cnpj(string $cnpj): JsonResponse
    {
        $cnpj = preg_replace('/\D/', '', $cnpj);

        if (strlen($cnpj) !== 14) {
            return response()->json(null, 204);
        }

        $dados = Cache::remember("cnpj:{$cnpj}", now()->addDay(), function () use ($cnpj) {
            foreach (self::PROVEDORES_DE_CNPJ as $url) {
                try {
                    $resposta = Http::connectTimeout(3)->timeout(5)->get($url.$cnpj);

                    if ($resposta->successful() && filled($resposta->json('razao_social'))) {
                        return $this->daReceita($resposta->json());
                    }

                    Log::info('Consulta de CNPJ sem resposta', ['provedor' => parse_url($url, PHP_URL_HOST), 'status' => $resposta->status()]);
                } catch (\Throwable $e) {
                    Log::warning('Consulta de CNPJ falhou', ['provedor' => parse_url($url, PHP_URL_HOST), 'erro' => $e->getMessage()]);
                }
            }

            return null;
        });

        return $dados ? response()->json($dados) : response()->json(null, 204);
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, ?string>
     */
    private function daReceita(array $d): array
    {
        // A Receita guarda o tipo do logradouro à parte ("AVENIDA" e
        // "REPUBLICA DO CHILE"), e às vezes repete o tipo no próprio
        // logradouro: juntar sem conferir daria "AVENIDA AVENIDA ...".
        $tipo = trim((string) ($d['descricao_tipo_de_logradouro'] ?? ''));
        $logradouro = trim((string) ($d['logradouro'] ?? ''));
        if ($tipo !== '' && $logradouro !== '' && ! str_starts_with(mb_strtoupper($logradouro), mb_strtoupper($tipo).' ')) {
            $logradouro = $tipo.' '.$logradouro;
        }

        $ibge = preg_replace('/\D/', '', (string) ($d['codigo_municipio_ibge'] ?? ''));

        return [
            'nome' => trim((string) $d['razao_social']),
            'email' => mb_strtolower(trim((string) ($d['email'] ?? ''))) ?: null,
            'logradouro' => $logradouro ?: null,
            'numero' => trim((string) ($d['numero'] ?? '')) ?: null,
            'bairro' => trim((string) ($d['bairro'] ?? '')) ?: null,
            // Em maiúsculas e sem acento, como a Receita grava. A tela
            // completa pelo CEP, que traz o nome como a nota deve mostrar.
            'cidade' => trim((string) ($d['municipio'] ?? '')) ?: null,
            'uf' => trim((string) ($d['uf'] ?? '')) ?: null,
            'cep' => preg_replace('/\D/', '', (string) ($d['cep'] ?? '')) ?: null,
            'ibge' => strlen($ibge) === 7 ? $ibge : null,
            // Emitir para empresa baixada ou inapta é nota que não deveria
            // sair; a tela avisa, quem emite decide.
            'situacao' => trim((string) ($d['descricao_situacao_cadastral'] ?? '')) ?: null,
        ];
    }
}
