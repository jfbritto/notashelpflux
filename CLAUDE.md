# CLAUDE.md

Emissor de NFS-e da HelpFlux (notas.helpflux.com.br). Um CNPJ só, o da
HELPFLUX SOLUCOES EM TECNOLOGIA LTDA (Santa Maria de Jetibá-ES, Simples
Nacional), e várias origens: a tela (nutrição e desenvolvimento, avulsas) e os
SaaS pela API (TreinaEdu e Hotel em Dia desde 13/10/2026). Quem assina e fala
com a prefeitura é a Notaas; o certificado A1 não entra aqui.

A fonte da verdade sobre escopo, domínio e contrato da API é
[a spec](docs/superpowers/specs/2026-08-11-notas-helpflux-design.md). O estado
atual está no [README](README.md), e a produção em [docs/deploy.md](docs/deploy.md).

## Regras que não se quebram

- **Teste que emite nota de verdade é problema fiscal, não bug.** O emissor
  falso (`FISCAL_EMISSOR=fake`) é forçado no `phpunit.xml`, no CI e no E2E, com
  `NOTAAS_API_KEY` vazia. Nenhum caminho novo pode depender de chave real.
- **Código fiscal só sai de [config/fiscal.php](config/fiscal.php).** Nunca do
  formulário nem do corpo da API: a tela escolhe entre perfis, e a API recebe
  origem e perfil do cadastro da aplicação (`aplicacoes`).
- **A tupla IBS/CBS de um perfil só entra copiada de uma nota real** emitida no
  portal para o mesmo serviço. Cada serviço tem a sua: software e
  desenvolvimento usam `000 / 000001 / 100501`, a nutrição usa
  `200 / 200029 / 030101`. Perfil novo fica sem o grupo até haver nota real.
- **A `aliquota` do perfil é o ISS (2,01%).** Os 6,00% do Simples Nacional são
  outro número e não entram no payload. Não trocar um pelo outro.
- **Toda mudança de estado passa por [FecharNota](app/Services/FecharNota.php)**:
  ela trava a linha, nunca devolve nota fechada a `processando` e dispara o
  retorno ao SaaS. Para anexar PDF e XML sem mexer na situação, use
  `FecharNota::anexarDocumentos`.
- **Comparar em tempo constante e nunca logar segredo.** A chave de API é
  guardada em hash e o segredo do retorno é cifrado; são segredos diferentes,
  com regras diferentes.

## Armadilhas da Notaas (já pagas)

A Notaas **ignora em silêncio** campo com nome errado e cai no padrão do
projeto, sem erro nenhum. Todo nome de campo novo precisa ser conferido em
https://docs.notaas.com.br/endpoints antes de ir para o payload
([PayloadDaNota](app/Services/Emissor/PayloadDaNota.php)).

- O prestador não vai no corpo (E0120 se for).
- `tomador.endereco` exige `cidade` e `uf`.
- O NBS vai só com dígitos.
- O local da prestação vai em `servico.localPrestacao`.
- A emissão e o cancelamento são assíncronos (202). O desfecho chega pelo
  webhook ou pela reconciliação (`notas:reconciliar`, a cada 5 min).
- A consulta de status só traz `pdfUrl`/`xmlUrl` quando `documentsCached=true`,
  e o `nfse.documents_ready` pode não vir. Por isso a reconciliação vai atrás
  da nota emitida sem documento no primeiro dia, e a lista tem "Buscar PDF".
- O número da nota é a chave de acesso, e um dos campos dela vem com prefixo
  `NFS`.

## Mapa

| onde | o quê |
|---|---|
| `app/Services/` | `EmitirNota` (tela e API), `FecharNota`, `CancelarNota`, `VerificarNota`, `AvisarAplicacao` |
| `app/Services/Emissor/` | contrato `Emissor`, `NotaasEmissor`, `EmissorFalso`, `PayloadDaNota` |
| `app/Http/Controllers/` | `NotaController` (tela), `Api/NotaApiController`, `NotaasWebhookController`, `ConsultaController` (CEP e CNPJ) |
| `app/Console/Commands/` | `usuario:criar`, `aplicacoes:criar`, `aplicacoes:revogar`, `notas:reconciliar` |
| `routes/console.php` | reconciliação a cada 5 min e `queue:work` a cada minuto (a VPS não tem supervisor) |
| `resources/views/notas/` | formulário (Alpine: máscaras, busca de CNPJ e CEP) e lista |
| `deploy/`, `.github/workflows/deploy.yml` | provisionamento, nginx e deploy por SSH |

## Comandos

O ambiente local roda em Docker, com MySQL também nos testes (porta 3311 no
host): o índice único com nulo, base da idempotência, se comporta diferente
no SQLite.

```bash
docker compose up -d mysql app
docker compose exec -T app php artisan test                 # PHPUnit
docker compose exec -T app php artisan test --filter=Nome   # um arquivo ou caso
docker compose exec -T app ./vendor/bin/pint --dirty        # estilo
npm run build && npx playwright test                        # E2E (no host, banco notas_e2e)
```

No E2E, as consultas de CEP e CNPJ são simuladas com `page.route`. Os testes
dividem o banco, então um teste que emite nota muda a lista que os seguintes
encontram.

## Como trabalhar aqui

- Ajuste pequeno ou médio vai direto: branch, testes da área, suíte inteira e
  E2E antes do PR. O merge é sempre do João. O deploy roda sozinho no push
  para `main` (testes e depois SSH); acompanhe com `gh run watch`.
- Produção (`/var/www/notas`, `php8.4`, usuário `deploy`) não é lida daqui.
  Quando precisar de um dado, passe ao João um comando que só lê e não
  imprime segredo nem dado do cliente.
- **Validar uma nota** é comparar o DANFSe com o de uma nota emitida no portal
  para o mesmo serviço (`pdftotext -layout`), campo a campo. Diferença só de
  aparência não é defeito:
  - a série da DPS (01608 é a da Notaas, 70000 a do emissor web);
  - NBS sem pontos, telefone sem máscara e município em maiúsculas;
  - "BR" no país;
  - a linha da Lei 12.741.
- Comentários em português explicam o porquê, com a data e o caso real que
  motivou a regra; siga a densidade do código em volta. Commits em português
  no formato `tipo(escopo): frase`. Textos visíveis sem travessão.
