<?php

/**
 * Mensagens de validação em português.
 *
 * Toda mensagem de erro que aparece nos formulários sai daqui. Sem este
 * arquivo, com o locale em pt_BR, o Laravel mostrava a chave crua na tela
 * ("auth.failed", "validation.required"), que não diz nada a quem está usando.
 */
return [
    'accepted' => 'É preciso aceitar :attribute.',
    'active_url' => ':attribute não é uma URL válida.',
    'after' => ':attribute precisa ser uma data depois de :date.',
    'after_or_equal' => ':attribute precisa ser uma data a partir de :date.',
    'alpha' => ':attribute só pode ter letras.',
    'alpha_dash' => ':attribute só pode ter letras, números, hífen e sublinhado.',
    'alpha_num' => ':attribute só pode ter letras e números.',
    'array' => ':attribute precisa ser uma lista.',
    'before' => ':attribute precisa ser uma data antes de :date.',
    'before_or_equal' => ':attribute precisa ser uma data até :date.',
    'between' => [
        'array' => ':attribute precisa ter entre :min e :max itens.',
        'file' => ':attribute precisa ter entre :min e :max kilobytes.',
        'numeric' => ':attribute precisa estar entre :min e :max.',
        'string' => ':attribute precisa ter entre :min e :max caracteres.',
    ],
    'boolean' => ':attribute precisa ser sim ou não.',
    'confirmed' => 'A confirmação de :attribute não confere.',
    'current_password' => 'A senha está incorreta.',
    'date' => ':attribute não é uma data válida.',
    'date_equals' => ':attribute precisa ser :date.',
    'date_format' => ':attribute não está no formato :format.',
    'declined' => ':attribute precisa ser recusado.',
    'different' => ':attribute e :other precisam ser diferentes.',
    'digits' => ':attribute precisa ter :digits dígitos.',
    'digits_between' => ':attribute precisa ter entre :min e :max dígitos.',
    'email' => ':attribute precisa ser um e-mail válido.',
    'ends_with' => ':attribute precisa terminar com um destes: :values.',
    'exists' => ':attribute não existe.',
    'file' => ':attribute precisa ser um arquivo.',
    'filled' => ':attribute é obrigatório.',
    'gt' => [
        'array' => ':attribute precisa ter mais de :value itens.',
        'file' => ':attribute precisa ter mais de :value kilobytes.',
        'numeric' => ':attribute precisa ser maior que :value.',
        'string' => ':attribute precisa ter mais de :value caracteres.',
    ],
    'gte' => [
        'array' => ':attribute precisa ter :value itens ou mais.',
        'file' => ':attribute precisa ter :value kilobytes ou mais.',
        'numeric' => ':attribute precisa ser :value ou mais.',
        'string' => ':attribute precisa ter :value caracteres ou mais.',
    ],
    'image' => ':attribute precisa ser uma imagem.',
    'in' => ':attribute não é uma opção válida.',
    'integer' => ':attribute precisa ser um número inteiro.',
    'lt' => [
        'array' => ':attribute precisa ter menos de :value itens.',
        'file' => ':attribute precisa ter menos de :value kilobytes.',
        'numeric' => ':attribute precisa ser menor que :value.',
        'string' => ':attribute precisa ter menos de :value caracteres.',
    ],
    'lte' => [
        'array' => ':attribute não pode ter mais de :value itens.',
        'file' => ':attribute não pode passar de :value kilobytes.',
        'numeric' => ':attribute não pode ser maior que :value.',
        'string' => ':attribute não pode ter mais de :value caracteres.',
    ],
    'max' => [
        'array' => ':attribute não pode ter mais de :max itens.',
        'file' => ':attribute não pode passar de :max kilobytes.',
        'numeric' => ':attribute não pode ser maior que :max.',
        'string' => ':attribute não pode ter mais de :max caracteres.',
    ],
    'mimes' => ':attribute precisa ser um arquivo do tipo: :values.',
    'mimetypes' => ':attribute precisa ser um arquivo do tipo: :values.',
    'min' => [
        'array' => ':attribute precisa ter pelo menos :min itens.',
        'file' => ':attribute precisa ter pelo menos :min kilobytes.',
        'numeric' => ':attribute precisa ser pelo menos :min.',
        'string' => ':attribute precisa ter pelo menos :min caracteres.',
    ],
    'not_in' => ':attribute não é uma opção válida.',
    'numeric' => ':attribute precisa ser um número.',
    'present' => ':attribute precisa estar presente.',
    'prohibited' => ':attribute não é permitido.',
    'regex' => ':attribute está num formato inválido.',
    'required' => ':attribute é obrigatório.',
    'required_if' => ':attribute é obrigatório quando :other é :value.',
    'required_with' => ':attribute é obrigatório quando :values está preenchido.',
    'required_without' => ':attribute é obrigatório quando :values não está preenchido.',
    'same' => ':attribute e :other precisam ser iguais.',
    'size' => [
        'array' => ':attribute precisa ter :size itens.',
        'file' => ':attribute precisa ter :size kilobytes.',
        'numeric' => ':attribute precisa ser :size.',
        'string' => ':attribute precisa ter :size caracteres.',
    ],
    'starts_with' => ':attribute precisa começar com um destes: :values.',
    'string' => ':attribute precisa ser um texto.',
    'unique' => ':attribute já está em uso.',
    'uploaded' => 'O envio de :attribute falhou.',
    'url' => ':attribute precisa ser uma URL válida.',

    'custom' => [
        'password' => [
            'min' => 'A senha precisa ter pelo menos :min caracteres.',
        ],
    ],

    /*
     * Como cada campo é chamado na frase de erro. Sem isto, a mensagem sairia
     * "tomador_nome é obrigatório", que é o nome da coluna, não o do campo que
     * a pessoa vê na tela.
     */
    'attributes' => [
        'email' => 'o e-mail',
        'password' => 'a senha',
        'name' => 'o nome',
        'tomador_tipo' => 'o tipo de cliente',
        'tomador_documento' => 'o CPF ou CNPJ',
        'tomador_nome' => 'o nome do cliente',
        'tomador_email' => 'o e-mail do cliente',
        'tomador_cep' => 'o CEP',
        'tomador_logradouro' => 'o logradouro',
        'tomador_numero' => 'o número',
        'tomador_complemento' => 'o complemento',
        'tomador_bairro' => 'o bairro',
        'tomador_cidade' => 'a cidade',
        'tomador_uf' => 'a UF',
        'tomador_ibge' => 'o município do cliente',
        'local_prestacao_nome' => 'onde o atendimento foi feito',
        'local_prestacao_ibge' => 'o município do atendimento',
        'descricao' => 'a descrição do serviço',
        'valor' => 'o valor',
    ],
];
