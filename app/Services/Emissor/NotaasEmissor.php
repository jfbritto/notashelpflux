<?php

namespace App\Services\Emissor;

use App\Models\Nota;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Emissão pelo padrão nacional da NFS-e, via Notaas.
 *
 * A emissão é assíncrona: o POST responde 202 e o resultado final chega no
 * webhook. O `invoiceId` que vem nessa resposta é gravado na hora, porque é o
 * único elo entre a nossa linha e a nota deles.
 */
class NotaasEmissor implements Emissor
{
    public function __construct(private PayloadDaNota $payload = new PayloadDaNota) {}

    public function enviar(Nota $nota): array
    {
        if (! $this->configurado()) {
            Log::error('Notaas: chave de API não configurada (NOTAAS_API_KEY).');

            return ['status' => 'erro', 'erro' => 'Emissor de NFS-e não configurado.'];
        }

        $corpo = $this->payload->montar($nota);
        $resposta = $this->http()->post('/emitir', $corpo);

        if ($resposta->successful()) {
            if ($id = $resposta->json('invoiceId')) {
                $nota->update(['notaas_invoice_id' => $id]);
            }

            return ['status' => $this->traduzir($resposta->json('status')) ?? 'processando'];
        }

        // O corpo vai junto no log: a API valida um campo por vez, e sem ver o
        // que foi enviado cada recusa vira um chute. Não há segredo nele, o
        // prestador não viaja no corpo.
        Log::error('Notaas: emissão recusada', [
            'status' => $resposta->status(),
            'resposta' => $resposta->json(),
            'corpo' => $corpo,
        ]);

        return [
            'status' => 'erro',
            'erro' => $resposta->json('message') ?? $resposta->json('error') ?? 'Falha ao enviar a nota para emissão.',
        ];
    }

    public function consultar(string $idNoEmissor): array
    {
        if (! $this->configurado()) {
            return ['status' => 'erro', 'erro' => 'Emissor de NFS-e não configurado.'];
        }

        $resposta = $this->http()->get("/invoices/{$idNoEmissor}/status");

        if (! $resposta->successful()) {
            return ['status' => 'processando'];
        }

        return $this->doRetorno($resposta->json() ?? []);
    }

    /**
     * Traduz o corpo da Notaas para o nosso formato. Usado pela consulta e pelo
     * webhook, que trazem os mesmos campos.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    public function doRetorno(array $dados): array
    {
        return match ($this->traduzir($dados['status'] ?? null)) {
            'emitida' => [
                'status' => 'emitida',
                'numero' => $this->numero($dados),
                'chave_acesso' => $this->chave($dados),
                'pdf_url' => $dados['pdfUrl'] ?? null,
                'xml_url' => $dados['xmlUrl'] ?? null,
            ],
            'cancelada' => ['status' => 'cancelada'],
            'erro' => [
                'status' => 'erro',
                'erro' => $dados['errorMessage'] ?? $dados['error'] ?? 'Erro na emissão da nota.',
            ],
            default => ['status' => 'processando'],
        };
    }

    /**
     * O número que o cliente vê.
     *
     * No padrão nacional a nota é identificada pela CHAVE DE ACESSO, e a Notaas
     * devolve a mesma chave em dois campos: crua num e com o prefixo "NFS" no
     * outro. Gravar o prefixado fez a tela de um cliente mostrar
     * "NFS32045592258...". Quando o campo traz um número curto de verdade, é
     * ele que vale, daí a comparação em vez de assumir um formato.
     *
     * @param  array<string, mixed>  $dados
     */
    private function numero(array $dados): ?string
    {
        $chave = $this->chave($dados);
        $numero = $this->semPrefixo($dados['nNFSe'] ?? $dados['numeroNfe'] ?? null);

        return ($numero === null || $numero === $chave) ? $chave : $numero;
    }

    /** @param  array<string, mixed>  $dados */
    private function chave(array $dados): ?string
    {
        return $this->semPrefixo($dados['chNFSe'] ?? $dados['nNFSe'] ?? $dados['numeroNfe'] ?? null);
    }

    private function semPrefixo(?string $valor): ?string
    {
        return $valor === null ? null : preg_replace('/^NFS/', '', $valor);
    }

    /** O status da Notaas no nosso vocabulário. */
    private function traduzir(?string $status): ?string
    {
        return match ($status) {
            'issued', 'authorized' => 'emitida',
            'cancelled', 'canceled' => 'cancelada',
            'error', 'rejected' => 'erro',
            'queued', 'processing' => 'processando',
            default => null,
        };
    }

    private function configurado(): bool
    {
        return filled(config('fiscal.notaas.api_key'));
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders(['x-api-key' => config('fiscal.notaas.api_key')])
            ->baseUrl(rtrim((string) config('fiscal.notaas.base_url'), '/'))
            ->acceptJson()
            ->timeout(30);
    }
}
