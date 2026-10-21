# notas.helpflux.com.br

Plataforma de emissão de NFS-e da HelpFlux. Um emitente só, várias origens:

| origem | como emite |
|---|---|
| TreinaEdu | pela API, a cada cobrança paga (desde 13/10/2026) |
| Hotel em Dia | pela API, a cada cobrança paga (ligado em 13/10/2026) |
| HelpDiet | pela API (depois; hoje não emite por aqui) |
| Nutrição e desenvolvimento | pela interface, avulsa |

Todas saem do mesmo CNPJ, o da HELPFLUX SOLUCOES EM TECNOLOGIA LTDA. Não são
vários emitentes: são origens do mesmo emitente. A plataforma existe para não
escrever o módulo de nota fiscal uma terceira vez e para tirar a emissão manual
do portal nacional.

Quem assina e conversa com a prefeitura continua sendo a Notaas. O certificado
A1 não entra aqui.

## Estado

No ar em notas.helpflux.com.br desde agosto/2026.

- **Emissão pela tela** (nutrição e desenvolvimento), lista com filtros,
  repetir nota, cancelamento com motivo e "Verificar agora". O CNPJ colado
  preenche o cliente pela Receita (BrasilAPI, com o minhareceita.org de
  reserva), e o CEP completa a cidade e o código IBGE.
- **Fechamento da nota** pelo webhook da Notaas (`/webhooks/notaas`, de pé
  desde o PR #6) e pela reconciliação (`notas:reconciliar`, a cada 5 min).
- **API para os SaaS** (`POST /api/notas`, `GET /api/notas/{referencia}`,
  `GET /api/notas`), autenticada por `x-api-key`, com idempotência por
  (origem, referência). Origem e perfil vêm do cadastro da aplicação, nunca
  do corpo. Aplicações são criadas e revogadas por comando:

  ```bash
  php artisan aplicacoes:criar "Hotel em Dia" hotelemdia software https://<saas>/webhooks/notas
  php artisan aplicacoes:revogar <id>
  ```

  A chave e o segredo do retorno aparecem uma vez só, na criação.
- **Retorno assinado** aos SaaS: `POST` na `callback_url` com
  `X-Notas-Event` (`nota.emitida`, `nota.erro`, `nota.cancelada`,
  `nota.documentos`) e `X-Notas-Signature: sha256=<HMAC-SHA256 do corpo>`.
  Três tentativas com espera crescente **só com fila de verdade**
  (`QUEUE_CONNECTION=database`; o agendador roda o `queue:work`). Com
  `sync`, o envio é uma tentativa só, na hora.
- **IBS/CBS** nos três perfis (`valores.ibscbs`), cada um com os valores de
  uma NFS-e real do Emissor Nacional para o mesmo serviço. A nutrição tem
  tupla própria (alíquota reduzida, IBS incidindo em Santa Maria de Jetibá).

Feito em 13/10/2026: a virada do TreinaEdu (spec §6 e §10) e o Hotel em Dia
ligado. Falta conferir a primeira nota real do TreinaEdu de ponta a ponta,
apagar o endpoint antigo do TreinaEdu no painel da Notaas e ligar o HelpDiet
([docs/deploy.md](docs/deploy.md), "A virada do TreinaEdu").

O documento de desenho está em
[docs/superpowers/specs/2026-08-11-notas-helpflux-design.md](docs/superpowers/specs/2026-08-11-notas-helpflux-design.md)
e é a fonte da verdade sobre escopo, domínio, contrato da API e a ordem da
virada do TreinaEdu.
