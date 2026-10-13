<?php

namespace App\Http\Resources;

use App\Models\Nota;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A nota como o SaaS a vê: na resposta da API e no corpo do retorno.
 *
 * Uma representação só para os dois caminhos de propósito. O `GET` é a rede
 * de segurança para quando o retorno se perde (spec §5); se os dois
 * mostrassem campos diferentes, o SaaS que se recupera pelo `GET` ficaria com
 * uma nota diferente da que teria recebido no retorno.
 *
 * Fica de fora o que é da plataforma e não do SaaS: id interno, id da
 * Notaas, quem emitiu, perfil e códigos fiscais.
 *
 * @mixin Nota
 */
class NotaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'referencia' => $this->referencia_externa,
            'status' => $this->status,
            'numero' => $this->numero,
            'chave_acesso' => $this->chave_acesso,
            'pdf_url' => $this->pdf_url,
            'xml_url' => $this->xml_url,
            'erro' => $this->erro,
            'emitida_em' => $this->emitida_em?->toIso8601String(),
        ];
    }
}
