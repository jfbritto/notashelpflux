<?php

namespace App\Http\Requests;

use App\Rules\ValidCpfCnpj;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * O corpo de `POST /api/notas` (spec §5).
 *
 * Repare no que NÃO está aqui, como no EmitirNotaRequest da tela: perfil,
 * códigos fiscais, origem e URL de retorno. Os quatro vêm do cadastro da
 * aplicação. Um SaaS não escolhe código fiscal, e quem roubar a chave não
 * redireciona o retorno. Campo a mais no corpo é simplesmente ignorado.
 */
class EmitirNotaPelaApiRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // A chave da idempotência: (origem, referência) é único na tabela.
            'referencia' => ['required', 'string', 'max:255'],

            'tomador' => ['required', 'array'],
            'tomador.tipo' => ['required', Rule::in(['pf', 'pj'])],
            'tomador.documento' => ['required', 'string', new ValidCpfCnpj],
            'tomador.nome' => ['required', 'string', 'max:255'],
            'tomador.email' => ['nullable', 'email', 'max:255'],

            // Cidade e UF obrigatórias: a Notaas recusa o endereço sem elas,
            // antes de chegar na prefeitura (spec §13).
            'tomador.endereco' => ['required', 'array'],
            'tomador.endereco.logradouro' => ['nullable', 'string', 'max:255'],
            'tomador.endereco.numero' => ['nullable', 'string', 'max:20'],
            'tomador.endereco.complemento' => ['nullable', 'string', 'max:255'],
            'tomador.endereco.bairro' => ['nullable', 'string', 'max:255'],
            'tomador.endereco.cidade' => ['required', 'string', 'max:255'],
            'tomador.endereco.uf' => ['required', 'string', 'size:2'],
            'tomador.endereco.cep' => ['nullable', 'string', 'max:9'],

            // O teto é o da coluna (decimal 10,2): passar dele seria erro de
            // banco, um 500 em vez de uma recusa que o SaaS entende.
            'valor' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'descricao' => ['nullable', 'string', 'max:1000'],
            'competencia' => ['nullable', 'date_format:Y-m'],
        ];
    }

    /**
     * O tipo tem de bater com o documento. CPF mandado como `pj` sairia no
     * campo `cnpj` do payload e a Notaas recusaria; melhor dizer aqui, na
     * hora, para quem pode corrigir.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $tipo = $this->input('tomador.tipo');
                $digitos = strlen(preg_replace('/\D/', '', (string) $this->input('tomador.documento')));

                if (($tipo === 'pf' && $digitos === 14) || ($tipo === 'pj' && $digitos === 11)) {
                    $validator->errors()->add('tomador.documento', $tipo === 'pf'
                        ? 'Tomador pessoa física precisa de CPF.'
                        : 'Tomador pessoa jurídica precisa de CNPJ.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'valor.min' => 'O valor da nota precisa ser maior que zero.',
            'competencia.date_format' => 'A competência vai no formato AAAA-MM.',
            'tomador.endereco.cidade.required' => 'A cidade do tomador é obrigatória na nota.',
            'tomador.endereco.uf.required' => 'A UF do tomador é obrigatória na nota.',
        ];
    }
}
