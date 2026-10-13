<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmitirNotaPelaApiRequest;
use App\Http\Resources\NotaResource;
use App\Models\Aplicacao;
use App\Models\Nota;
use App\Services\EmitirNota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A API dos SaaS (spec §5): criar, consultar e listar.
 *
 * Toda leitura é recortada pela ORIGEM da aplicação autenticada. Uma
 * aplicação não enxerga nota de outra, nem as manuais: referência de outra
 * origem responde 404, como se não existisse, e não 403, que confirmaria que
 * ela existe.
 */
class NotaApiController extends Controller
{
    /**
     * Cria e envia. 201 com a nota nova; 200 com a existente quando a
     * referência já foi usada por esta origem, sem reenviar ao emissor. É o
     * que deixa o SaaS repetir a chamada sem medo depois de um timeout.
     */
    public function store(EmitirNotaPelaApiRequest $request, EmitirNota $emitirNota): JsonResponse
    {
        $aplicacao = $this->aplicacao($request);
        $dados = $request->validated();
        $perfil = config("fiscal.perfis.{$aplicacao->perfil}");
        $endereco = $dados['tomador']['endereco'];

        // O mesmo caminho da tela (EmitirNota), inclusive na idempotência.
        // As colunas são montadas aqui, campo a campo, e nunca a partir do
        // corpo inteiro: origem e perfil só podem vir da aplicação.
        $nota = $emitirNota->emitir([
            'origem' => $aplicacao->origem,
            'referencia_externa' => $dados['referencia'],
            'perfil' => $aplicacao->perfil,

            'tomador_tipo' => $dados['tomador']['tipo'],
            'tomador_documento' => preg_replace('/\D/', '', $dados['tomador']['documento']),
            'tomador_nome' => $dados['tomador']['nome'],
            'tomador_email' => $dados['tomador']['email'] ?? null,
            'tomador_cep' => preg_replace('/\D/', '', (string) ($endereco['cep'] ?? '')) ?: null,
            'tomador_logradouro' => $endereco['logradouro'] ?? null,
            'tomador_numero' => $endereco['numero'] ?? null,
            'tomador_complemento' => $endereco['complemento'] ?? null,
            'tomador_bairro' => $endereco['bairro'] ?? null,
            'tomador_cidade' => $endereco['cidade'],
            'tomador_uf' => strtoupper($endereco['uf']),

            ...$this->localDaPrestacao($perfil),

            'descricao' => $dados['descricao'] ?? $perfil['descricao_padrao'],
            'valor' => $dados['valor'],
            'competencia' => $dados['competencia'] ?? now()->format('Y-m'),
            // Nota de API não tem pessoa por trás; a origem já diz quem foi.
            'criada_por' => null,
        ]);

        return NotaResource::make($nota)
            ->response()
            ->setStatusCode($nota->wasRecentlyCreated ? 201 : 200);
    }

    /** A rede de segurança para quando o retorno se perde. */
    public function show(Request $request, string $referencia): NotaResource|JsonResponse
    {
        $nota = Nota::where('origem', $this->aplicacao($request)->origem)
            ->where('referencia_externa', $referencia)
            ->first();

        if (! $nota) {
            return response()->json(['message' => 'Nota não encontrada.'], 404);
        }

        return NotaResource::make($nota);
    }

    /** Para conciliação: todas as notas da origem, mais novas primeiro. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $notas = Nota::where('origem', $this->aplicacao($request)->origem)
            ->latest('id')
            ->paginate(50);

        return NotaResource::collection($notas);
    }

    private function aplicacao(Request $request): Aplicacao
    {
        return $request->attributes->get('aplicacao');
    }

    /**
     * O local da prestação é o padrão do perfil da aplicação.
     *
     * Só existe o caso do prestador: a API não recebe código IBGE do tomador,
     * e o aplicacoes:criar recusa perfil que peça o local do tomador. Chegar
     * aqui com outro perfil é configuração mudada por fora, e emitir com um
     * local inventado seria pior do que falhar alto.
     *
     * @param  array<string, mixed>  $perfil
     * @return array{local_prestacao_ibge: string, local_prestacao_nome: string}
     */
    private function localDaPrestacao(array $perfil): array
    {
        if (($perfil['local_prestacao_padrao'] ?? null) !== 'prestador') {
            throw new \LogicException('Perfil de aplicação com local da prestação do tomador: a API não sabe emiti-lo.');
        }

        return [
            'local_prestacao_ibge' => config('fiscal.prestador.codigo_municipio'),
            'local_prestacao_nome' => config('fiscal.prestador.nome_municipio'),
        ];
    }
}
