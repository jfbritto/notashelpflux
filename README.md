# notas.helpflux.com.br

Plataforma de emissão de NFS-e da HelpFlux. Um emissor só, três origens:

| origem | como emite |
|---|---|
| TreinaEdu | pela API, a cada cobrança paga |
| HelpDiet | pela API (depois; hoje não emite nota) |
| Nutrição | pela interface, avulsa |

Todas saem do mesmo CNPJ, o da HELPFLUX SOLUCOES EM TECNOLOGIA LTDA. Não são
três emitentes: são três origens do mesmo emitente. A plataforma existe para não
escrever o módulo de nota fiscal uma terceira vez e para tirar a emissão manual
do portal nacional.

Quem assina e conversa com a prefeitura continua sendo a Notaas. O certificado
A1 não entra aqui.

## Estado

Em desenho. Nada implementado ainda.

O documento de desenho está em
[docs/superpowers/specs/2026-08-11-notas-helpflux-design.md](docs/superpowers/specs/2026-08-11-notas-helpflux-design.md)
e é a fonte da verdade sobre escopo, domínio, contrato da API e a ordem da
virada do TreinaEdu.
