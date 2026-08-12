# Plataforma de notas fiscais da HelpFlux (notas.helpflux.com.br)

**Data:** 11/08/2026
**Contexto:** centralizar a emissão de NFS-e do TreinaEdu, do HelpDiet e dos
atendimentos de nutrição num só lugar, em vez de espalhar o mesmo código de
emissão por cada SaaS e continuar digitando nota no portal nacional.

**Sobre os caminhos de arquivo:** referências marcadas com `treinaedu:` são do
repositório do TreinaEdu, que já emite NFS-e hoje e é de onde vêm as decisões e
as armadilhas registradas aqui. Todas as outras são deste projeto.

---

## 1. O problema

A HelpFlux emite NFS-e de três origens, hoje por três caminhos diferentes:

| origem | como emite hoje | onde vive |
|---|---|---|
| TreinaEdu | automático, a cada cobrança paga | `treinaedu:app/Jobs/EmitNfseJob.php` |
| HelpDiet | não emite | Laravel 8.75, ligado à plataforma depois (§11) |
| Nutrição | na mão, no portal nacional (série 70000) | nenhum sistema |

As três saem do **mesmo emitente**: HELPFLUX SOLUCOES EM TECNOLOGIA LTDA,
CNPJ 58.063.432/0001-21, Santa Maria de Jetibá-ES (IBGE 3204559), Simples
Nacional, inscrição municipal 0000032065. Não são três emitentes, são três
origens do mesmo emitente.

Daí decorrem os dois custos que o projeto ataca:

1. **Escrever emissão no HelpDiet seria a segunda cópia** do módulo que o
   TreinaEdu acabou de estabilizar (driver, webhook assinado, notificação,
   guarda de nota de teste). Cada gotcha do padrão nacional teria de ser
   reaprendido lá.
2. **A emissão manual é digitação em portal de governo.** Quem emite não deveria
   precisar conhecer código de tributação nem código IBGE.

---

## 2. As decisões que delimitam o projeto

| decisão | escolha | o que isso tira do escopo |
|---|---|---|
| Quantos emitentes | **Um só**, o CNPJ da HelpFlux | Cadastro de empresas, certificado por tenant, multi-CNPJ |
| Quem fala com a prefeitura | **A Notaas**, como hoje | Assinar XML, certificado A1 no nosso servidor, layout do padrão nacional |
| Como os SaaS emitem | **API HTTP com chave por aplicação** e retorno assinado | Banco compartilhado, fila comum, acoplamento entre os sistemas |
| Emissão de nutrição | **Avulsa**: preenche e emite | Cadastro de pacientes, agenda, recorrência, contrato |
| Local da prestação | **Campo da nota**, sugerindo a cidade do cliente | Fixá-lo no perfil de serviço |
| Tomador | **PF e PJ** | Assumir que todo cliente é empresa |

---

## 3. O que a nota de nutrição ensinou

A NFS-e real de nutrição emitida em 06/08/2026 (tomador pessoa jurídica em
Vitória) não é a nota de software com outro texto. Comparada com o que o
TreinaEdu manda hoje (`treinaedu:config/nfse.php:47-57`):

| campo | software | nutrição |
|---|---|---|
| Código de tributação | 01.05.01 | **04.10.01** |
| Item da LC 116 | 1.05 | 4.10 |
| Código NBS | não informado | 1.2301.99.00 |
| Descrição | fixa | varia por nota ("Atendimentos nutricionais" mais o projeto) |
| Local da prestação | Santa Maria de Jetibá | **Vitória** |
| Município de incidência do ISS | Santa Maria de Jetibá | Santa Maria de Jetibá |

As duas últimas linhas são a descoberta que muda a modelagem: **local da
prestação e município de incidência do ISS são campos diferentes e podem
divergir na mesma nota.** O atendimento aconteceu em Vitória, o ISS é devido em
Santa Maria de Jetibá, que é onde o prestador está.

O driver atual manda um único `servico.codigoMunicipio`, sempre o do prestador
(`treinaedu:app/Services/Nfse/NotaasNfseDriver.php:90`). Para software está certo e
continua certo. Para nutrição, o local vira dado da nota.

Nas duas notas a alíquota sai em branco no DANFSe, porque optante do Simples
apura o ISS no DAS. O perfil ainda carrega `aliquota` porque é o que mandamos
hoje; se a primeira emissão de nutrição confirmar que o nacional ignora o campo
para optante, ele sai do perfil.

---

## 4. Domínio

### 4.1 A tabela `notas`

Uma tabela para as três origens. O que muda entre elas é o valor da coluna
`origem`, não o formato.

| coluna | por quê |
|---|---|
| `origem` | `treinaedu` \| `helpdiet` \| `manual` |
| `referencia_externa` | a referência do SaaS de origem; nula em nota manual |
| `perfil` | qual perfil de serviço foi usado (`software` \| `nutricao`) |
| `tomador_tipo`, `tomador_documento`, `tomador_nome`, `tomador_email` | quem recebe |
| `tomador_cidade`, `tomador_uf`, `tomador_ibge`, `tomador_cep`, logradouro/número/bairro | endereço do tomador |
| `local_prestacao_ibge`, `local_prestacao_nome` | onde o serviço foi prestado |
| `descricao` | o texto que sai na nota |
| `valor`, `competencia` | valor e mês de competência |
| `status` | `processando` \| `emitida` \| `erro` \| `cancelada` |
| `notaas_invoice_id` | o id da Notaas; é por ele que o webhook encontra a nota |
| `chave_acesso`, `numero` | identificação no padrão nacional |
| `pdf_url`, `xml_url` | links públicos que a Notaas devolve |
| `erro` | mensagem da recusa, para aparecer na tela |
| `emitida_em`, `criada_por` | quando fechou e quem emitiu (nulo quando veio pela API) |

`(origem, referencia_externa)` é único. É essa restrição que dá idempotência
sem código extra: o mesmo pagamento reenviado não vira duas notas. Nota manual
grava `referencia_externa` nula, e o MySQL trata nulos como distintos entre si,
então o índice não atrapalha a emissão avulsa.

### 4.2 Perfis de serviço

Ficam em `config/fiscal.php`, versionados, não numa tela. São dados fiscais que
mudam raramente e erram caro.

```php
'perfis' => [
    'software' => [
        'rotulo' => 'Licenciamento de software',
        'item_lista_servico' => '1.05',
        'codigo_tributacao_nacional' => '010501',
        'nbs' => null,
        'descricao_padrao' => 'Mensalidade referente ao uso da plataforma :produto',
        'local_prestacao_padrao' => 'prestador',
        'aliquota' => 2.01,
    ],
    'nutricao' => [
        'rotulo' => 'Atendimento nutricional',
        'item_lista_servico' => '4.10',
        'codigo_tributacao_nacional' => '041001',
        'nbs' => '1.2301.99.00',
        'descricao_padrao' => 'Atendimentos nutricionais',
        'local_prestacao_padrao' => 'tomador',
        'aliquota' => 2.01,
    ],
],
```

`local_prestacao_padrao` diz apenas o que a tela **sugere**. A nota grava o
município escolhido.

### 4.3 A tabela `aplicacoes`

Cada SaaS que emite pela API é uma linha. É ela que responde "quem é você e o
que você pode emitir".

| coluna | por quê |
|---|---|
| `nome`, `origem` | rótulo e o valor que vai para `notas.origem` |
| `perfil` | perfil de serviço fixo da aplicação; o SaaS não escolhe código fiscal |
| `chave_hash` | hash da chave de API, para autenticar a chamada |
| `callback_url` | para onde vai o retorno |
| `segredo_callback` | com o que o retorno é assinado |
| `revogada_em` | revogar sem apagar histórico |

**`chave_hash` e `segredo_callback` são dois segredos diferentes, e precisam
ser.** A chave de API só é verificada, então guardamos o hash e a exibimos uma
única vez na criação. O segredo do retorno precisa ser lido para assinar, então
é guardado cifrado (`encrypted` cast), não hasheado. Confundir os dois deixaria
o retorno impossível de assinar ou a chave recuperável em texto claro.

---

## 5. A API para os SaaS

Três rotas, autenticadas por `x-api-key`:

- `POST /api/notas` cria e envia. Responde 201 com o estado da nota. Se a
  referência já existe para aquela origem, responde 200 com a nota existente,
  sem reenviar.
- `GET /api/notas/{referencia}` consulta. É a rede de segurança para quando o
  retorno se perder.
- `GET /api/notas` lista, para conciliação.

O corpo da criação carrega referência, tomador, valor, descrição opcional e
competência opcional. **Não carrega perfil de serviço nem callback:** os dois
vêm do cadastro da aplicação. Um SaaS não escolhe código fiscal, e um invasor de
posse da chave não redireciona o retorno.

### O retorno

Quando a nota muda de estado **ou ganha documentos**, a plataforma faz `POST` na
URL cadastrada da aplicação, assinado com HMAC-SHA256 do corpo no header
`X-Notas-Signature`, e o tipo do evento em `X-Notas-Event`. Prender o envio do
retorno a uma transição de status derrubaria calado o quarto evento. Se o destino falhar, o job repete três vezes
com espera crescente. Depois disso a nota continua correta na plataforma e o
SaaS a alcança pelo `GET`.

São **quatro** eventos, e o quarto é o que quase ficou de fora:

| evento | quando | o que o SaaS faz |
|---|---|---|
| `nota.emitida` | a nota foi autorizada | grava número, chave e avisa o cliente |
| `nota.erro` | recusada | grava a mensagem |
| `nota.cancelada` | cancelada | grava o estado |
| `nota.documentos` | PDF ou XML ficaram prontos | anexa os links, **sem mexer no status nem avisar de novo** |

O quarto existe porque a Notaas manda `nfse.documents_ready` **depois** da
emissão, e às vezes duas vezes, o XML primeiro e o PDF depois. Hoje esse evento
chega direto no TreinaEdu, que anexa os links
(`treinaedu:app/Http/Controllers/NotaasNfseWebhookController.php:55-62`). Depois da virada
ele passa a chegar na plataforma. Sem repassá-lo, a nota do cliente ficaria
marcada como emitida e sem PDF para baixar, que é exatamente o que a tela de
notas mostra (`treinaedu:resources/views/subscription/invoices.blade.php:58-66`).

É o mesmo desenho do webhook que a Notaas usa conosco, e por isso o TreinaEdu já
sabe consumi-lo.

---

## 6. O lado do TreinaEdu

O módulo de NFS-e já é plugável. O emissor é resolvido no container
(`treinaedu:app/Providers/AppServiceProvider.php:38-42`):

```php
$this->app->bind(NfseDriver::class, fn () => match (config('nfse.driver')) {
    'fake' => new FakeNfseDriver(),
    'focus' => new FocusNfseDriver(),
    default => new NotaasNfseDriver(),
});
```

A mudança no TreinaEdu é pequena e reversível:

1. Um `PlataformaNfseDriver` implementando a mesma interface de dois métodos
   (`treinaedu:app/Services/Nfse/NfseDriver.php:21-29`). O `emit()` faz `POST /api/notas`,
   o `consult()` faz o `GET`.
2. Uma entrada `'plataforma'` no `match`.
3. Uma rota de retorno ao lado das duas que já existem
   (`treinaedu:routes/web.php:108-116`), verificando a assinatura e tratando os quatro
   eventos. Ela é quase uma cópia do controller da Notaas, inclusive na regra de
   notificar só na transição de status e na de `nota.documentos` só anexar
   links.

Nada do resto muda: `EmitNfseJob`, `NfseNotifier`, a tela de notas do cliente e
os testes que usam o `FakeNfseDriver` continuam iguais. **Rollback é trocar
`NFSE_DRIVER` de volta para `notaas`.**

---

## 7. A tela de emissão manual

Um formulário, sem jargão fiscal:

- **Cliente**: alterna entre CPF e CNPJ. Com CNPJ, a plataforma busca razão
  social e endereço pelo documento e ela só confere. Com CPF, ela digita nome e
  cidade, e o CEP preenche cidade, UF e código IBGE.
- **Onde o atendimento foi feito**: já vem com a cidade do cliente, editável.
  É o campo que vira o local da prestação.
- **Valor**.
- **Descrição do serviço**: já vem com o texto padrão do perfil, editável, que
  é por onde entra o complemento de cada nota (o nome do projeto ou do estudo,
  por exemplo).

Nenhum código de tributação, item de lista ou IBGE é digitado. Eles vêm do
perfil e da busca por documento.

A lista de notas mostra data, cliente, valor, situação e os links de PDF e XML,
e cada linha tem **repetir nota**, que reabre o formulário preenchido com aquele
cliente. É o que resolve o paciente recorrente sem construir cadastro de
pacientes.

Dois papéis: `admin` (eu) vê todas as origens e administra as chaves de API;
`emissor` (minha esposa) emite e vê as notas manuais. Sem cadastro aberto: os
usuários são criados por comando.

---

## 8. Segurança

- **O certificado A1 não entra na plataforma.** Continua na conta da Notaas, que
  assina. Guardamos a chave de API deles, no `.env`.
- **Chaves das aplicações ficam hasheadas** no banco e são exibidas uma única
  vez, na criação. Revogar é marcar `revogada_em`. O segredo do retorno é outro
  campo e segue outra regra: cifrado, não hasheado, porque precisa ser lido para
  assinar (§4.3).
- **Assinatura nos dois sentidos**: verificamos o HMAC da Notaas ao receber e
  assinamos o retorno aos SaaS, sempre com comparação em tempo constante.
- **Nenhum segredo em log.** O payload da emissão vai para o log em caso de erro
  (foi o que encurtou a depuração no TreinaEdu), mas ele não contém segredo:
  o prestador não viaja no corpo.

---

## 9. Testes

O risco aqui é diferente do resto: **teste que emite nota de verdade é problema
fiscal, não bug.** A plataforma nasce com um emissor falso ligado em `testing` e
no E2E, como o `FakeNfseDriver` faz hoje (`treinaedu:app/Providers/E2EServiceProvider.php:22`).

Caminhos cobertos:

| caminho | tipo |
|---|---|
| Emitir pela tela e ver a nota na lista com PDF | Playwright |
| Repetir nota preenche o formulário | Playwright |
| Chave de API inválida ou revogada recebe 401 | PHPUnit |
| Mesma referência não vira duas notas | PHPUnit |
| Aplicação com `origem = helpdiet` emite pelo seu próprio perfil | PHPUnit |
| Webhook da Notaas fecha a nota e dispara o retorno | PHPUnit |
| Assinatura inválida no webhook recebe 401 | PHPUnit |
| Retorno é assinado com o segredo da aplicação | PHPUnit |
| `documents_ready` vira `nota.documentos`, anexa links e não muda o status | PHPUnit |
| Local da prestação diferente do prestador chega no payload | PHPUnit |

Do lado do TreinaEdu, o `PlataformaNfseDriver` ganha o mesmo tratamento que o
`NotaasNfseDriver` tem em `treinaedu:tests/Feature/Billing/NfseNotaasTest.php`.

---

## 10. Deploy e a virada

Laravel 11 com Vite e Tailwind, mesma estrutura do TreinaEdu, no diretório
`/Users/joaofilipibritto/Projetos/notashelpflux`, repositório próprio. Na VPS
vira `notas.helpflux.com.br`: nginx, php-fpm, banco próprio, deploy por GitHub
Actions rodando script como usuário `deploy`, igual aos outros.

**A virada do TreinaEdu tem uma janela perigosa e precisa ser um ato só.** O
webhook da conta Notaas hoje aponta para o TreinaEdu. No momento em que passar a
apontar para a plataforma, nota emitida pelo caminho antigo deixa de fechar
sozinha. A ordem é:

1. Plataforma no ar, com a rota de webhook respondendo.
2. Webhook da Notaas apontado para a plataforma **e** `NFSE_DRIVER=plataforma`
   no TreinaEdu, na mesma janela.
3. Conferir a primeira cobrança real de ponta a ponta antes de considerar
   migrado.

Faz-se isso quando não houver nota em `processando` no TreinaEdu.

---

## 11. Fora do escopo

Cadastro de pacientes, agenda, recorrência. Múltiplos CNPJs emitentes.
Relatórios fiscais e contábeis. Carta de correção. Notas de outros municípios
que não Santa Maria de Jetibá. Cadastro aberto de usuários.

**Ligar o HelpDiet à plataforma não entra neste plano.** Ele motiva o projeto,
e é para não escrever emissão lá dentro que a API existe, mas o HelpDiet hoje
não emite nota nenhuma e roda em Laravel 8.75, com outra estrutura. A API nasce
suportando `origem = helpdiet` e é testada com ela; mexer no HelpDiet é um plano
seu, depois que a plataforma estiver no ar e o TreinaEdu migrado.

**Cancelamento de nota** fica para a fase 2, com uma ressalva: o webhook da
Notaas já prevê `nfse.cancelled`, então o estado existe no fluxo deles. Se a API
expuser endpoint de cancelamento, ele é barato e entra na fase 1; enquanto não
entrar, cancelar é ir ao portal nacional, e a plataforma reflete pelo webhook.

---

## 12. Riscos

| risco | mitigação |
|---|---|
| Plano gratuito da Notaas é de 50 notas/mês, e passamos a somar três origens | Conferir o limite antes da virada; o custo do plano pago entra na decisão |
| O webhook único da conta Notaas força a virada em janela | Fazer com a fila vazia, e o `GET` de consulta como rede |
| Perfil de nutrição errado gera nota fiscal errada | Primeira emissão real conferida contra o DANFSe de 06/08/2026, campo a campo |
| Busca de CNPJ por serviço externo indisponível | Degradar para digitação; a busca é conveniência, não dependência |
| Emitir de verdade a partir de teste | Emissor falso ligado em `testing` e no E2E, sem chave real no ambiente |

---

## 13. O que já está resolvido e não pode ser reaprendido

Estes são gotchas pagos na migração do TreinaEdu para o padrão nacional. Valem
igual na plataforma nova:

- **O prestador não vai no corpo da emissão.** Ele é o dono da chave de API. Se
  mandar a inscrição municipal do prestador, a Notaas recusa com E0120.
- **`tomador.endereco` exige `cidade` e `uf`** junto do código IBGE, ou a API
  recusa antes de chegar na prefeitura.
- **O número da nota é a chave de acesso**, 50 dígitos. A Notaas devolve a mesma
  chave em dois campos, e num deles com o prefixo `NFS`, que precisa ser tirado
  antes de gravar (`treinaedu:app/Services/Nfse/NotaasNfseDriver.php:163-186`).
- **E0037 é ambiente, não adesão.** Homologação não tem convênio municipal;
  Santa Maria de Jetibá está no nacional desde 03/08/2026.
- **Webhook e consulta podem fechar a nota no mesmo segundo.** Quem fechar
  primeiro avisa o cliente, e só na transição de estado, senão o cliente recebe
  dois e-mails ou nenhum.
- **Os documentos chegam depois da emissão**, em evento próprio e às vezes
  repetido. Anexar links não é mudar de estado e não avisa ninguém de novo.
