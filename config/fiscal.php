<?php

return [
    /*
     * Emissor ligado: 'notaas' em produção, 'fake' em teste e no E2E.
     *
     * Teste que emite nota de verdade é problema fiscal, não bug. O falso é
     * forçado no phpunit.xml, no docker-compose e no ambiente de E2E, e a
     * chave real nunca entra nesses ambientes.
     */
    'emissor' => env('FISCAL_EMISSOR', 'notaas'),

    'notaas' => [
        // SEGREDO: só no .env, nunca commitado.
        'api_key' => env('NOTAAS_API_KEY'),
        'base_url' => env('NOTAAS_BASE_URL', 'https://platform.notaas.com.br/api/v1'),
        // Segredo do webhook (HMAC-SHA256 do corpo, header X-Notaas-Signature).
        // Vazio dispensa a verificação, que é o estado do primeiro dia, antes
        // de cadastrar o endpoint no painel deles.
        'webhook_secret' => env('NOTAAS_WEBHOOK_SECRET'),
    ],

    /*
     * O emitente: HELPFLUX SOLUCOES EM TECNOLOGIA LTDA.
     *
     * CNPJ e município saem impressos em toda nota, não são segredo. O
     * certificado A1 NÃO está aqui: ele vive na conta da Notaas, que assina.
     * A inscrição municipal do prestador também não: mandá-la no corpo da
     * emissão faz a Notaas recusar com E0120.
     */
    'prestador' => [
        'cnpj' => env('FISCAL_CNPJ', '58063432000121'),
        'codigo_municipio' => env('FISCAL_IBGE', '3204559'), // Santa Maria de Jetibá-ES
        'nome_municipio' => env('FISCAL_MUNICIPIO', 'Santa Maria de Jetibá'),
    ],

    /*
     * Perfis de serviço.
     *
     * Os códigos de nutrição foram conferidos contra uma DANFSe real de
     * 06/08/2026; os de software são os que o TreinaEdu emite há meses.
     *
     * `local_prestacao_padrao` diz só o que a TELA SUGERE. A nota grava o
     * município escolhido, porque local da prestação e município de incidência
     * do ISS são campos diferentes e divergem: naquela nota o atendimento foi
     * em Vitória e o ISS ficou em Santa Maria de Jetibá.
     */
    'perfis' => [
        /*
         * Assinatura de SaaS. Sai sozinha, a cada cobrança paga, pela API.
         * `manual => false` tira do formulário: o que é automático não deve
         * estar à mão para alguém emitir em duplicidade sem perceber.
         *
         * Confirmado contra nota real (RPS 189, 04/08/2026, mensalidade do
         * HelpDiet para a Natal Ponta Negra Hotel LTDA).
         */
        'software' => [
            'rotulo' => 'Licenciamento de software',
            'item_lista_servico' => '1.05',
            'codigo_tributacao_nacional' => '010501',
            'nbs' => '1.1103.22.00',
            'descricao_padrao' => 'Licenciamento de uso de software',
            'local_prestacao_padrao' => 'prestador',
            'aliquota' => 2.01,
            'manual' => false,
        ],

        /*
         * Desenvolvimento sob medida, cobrado por projeto.
         *
         * Confirmado contra nota real (RPS 181, 06/07/2026, para a IJR Media
         * Holdings LLC, cliente de desenvolvimento recorrente): 1.01 é
         * mesmo "análise e desenvolvimento de sistemas". Antes deste
         * documento aparecer, este item estava marcado como pendente de
         * confirmação com a contabilidade; a nota real resolve a dúvida.
         */
        'desenvolvimento' => [
            'rotulo' => 'Desenvolvimento de sistemas',
            'item_lista_servico' => '1.01',
            'codigo_tributacao_nacional' => '010101',
            'nbs' => '1.1502.20.00',
            'descricao_padrao' => 'Serviços de análise e desenvolvimento de sistemas',
            'local_prestacao_padrao' => 'prestador',
            'aliquota' => 2.01,
            'manual' => true,
        ],

        /*
         * Atendimento nutricional. Códigos conferidos contra a DANFSe de
         * 06/08/2026.
         */
        'nutricao' => [
            'rotulo' => 'Atendimento nutricional',
            'item_lista_servico' => '4.10',
            'codigo_tributacao_nacional' => '041001',
            'nbs' => '1.2301.99.00',
            'descricao_padrao' => 'Atendimentos nutricionais',
            'local_prestacao_padrao' => 'tomador',
            'aliquota' => 2.01,
            'manual' => true,
        ],
    ],
];
