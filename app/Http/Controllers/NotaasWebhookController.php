<?php

namespace App\Http\Controllers;

use App\Models\Nota;
use App\Services\Emissor\NotaasEmissor;
use App\Services\FecharNota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Retorno assíncrono da Notaas: emitida, erro, cancelada, documentos prontos.
 *
 * Três coisas que este controller precisa acertar, e que custaram caro para
 * descobrir no TreinaEdu:
 *
 *   1. A nota é achada pelo ID DO EMISSOR, guardado na emissão. A referência
 *      serve de plano B, e nota manual não tem nenhuma.
 *   2. O corpo é ASSINADO. Sem conferir, qualquer um poderia postar "nota
 *      emitida" e a plataforma acreditaria.
 *   3. `nfse.documents_ready` chega DEPOIS da emissão, e pode chegar duas
 *      vezes (XML primeiro, PDF depois). Ele só anexa links.
 *
 * Responde 200 sempre que a requisição é legítima, mesmo para nota que não
 * conhecemos, para o emissor não ficar reenfileirando entrega eternamente.
 */
class NotaasWebhookController extends Controller
{
    public function handle(Request $request, FecharNota $fecharNota, NotaasEmissor $emissor): JsonResponse
    {
        if (! $this->assinaturaValida($request)) {
            Log::warning('Notaas webhook: assinatura inválida', ['ip' => $request->ip()]);

            return response()->json(['ok' => false], 401);
        }

        Log::info('Notaas webhook recebido', $request->all());

        // O tipo vem no corpo e também no header. Aceitamos os dois, com o
        // corpo na frente: depender só de um é apostar em qual eles mantêm.
        $evento = (string) ($request->input('event') ?: $request->header('X-Notaas-Event'));
        $dados = (array) $request->input('data', []);

        $nota = $this->acharNota($dados);

        if (! $nota) {
            return response()->json(['ok' => true]);
        }

        // Documentos prontos não mexem em estado, só trazem os links.
        if ($evento === 'nfse.documents_ready') {
            $fecharNota->aplicar($nota, [
                'status' => 'processando',
                'pdf_url' => $dados['pdfUrl'] ?? null,
                'xml_url' => $dados['xmlUrl'] ?? null,
            ]);

            return response()->json(['ok' => true]);
        }

        $fecharNota->aplicar($nota, $emissor->doRetorno(
            $dados + ['status' => $dados['status'] ?? $this->statusDoEvento($evento)]
        ));

        return response()->json(['ok' => true]);
    }

    /** O evento diz o status quando o corpo não repete o campo. */
    private function statusDoEvento(string $evento): ?string
    {
        return match ($evento) {
            'nfse.issued' => 'issued',
            'nfse.error' => 'error',
            'nfse.cancelled' => 'cancelled',
            default => null,
        };
    }

    /** @param  array<string, mixed>  $dados */
    private function acharNota(array $dados): ?Nota
    {
        if ($id = $dados['invoiceId'] ?? null) {
            if ($nota = Nota::where('notaas_invoice_id', $id)->first()) {
                return $nota;
            }
        }

        if ($ref = $dados['referencia'] ?? ($dados['reference'] ?? null)) {
            return Nota::where('referencia_externa', $ref)->first();
        }

        return null;
    }

    /**
     * HMAC-SHA256 do corpo CRU, comparado em tempo constante.
     *
     * Sem segredo configurado a verificação é dispensada: é o estado do
     * primeiro dia, antes de cadastrar o endpoint no painel da Notaas. Assim
     * que o segredo entra no .env, webhook sem assinatura para de passar.
     */
    private function assinaturaValida(Request $request): bool
    {
        $segredo = config('fiscal.notaas.webhook_secret');

        if (blank($segredo)) {
            return true;
        }

        return hash_equals(
            'sha256='.hash_hmac('sha256', $request->getContent(), $segredo),
            (string) $request->header('X-Notaas-Signature'),
        );
    }
}
