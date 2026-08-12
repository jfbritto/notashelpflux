<?php

namespace App\Http\Requests;

use App\Rules\ValidCpfCnpj;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * O que a tela de emissão aceita.
 *
 * Repare no que NÃO está aqui: perfil de serviço, código de tributação, item
 * da lista, alíquota. Nada disso vem do formulário. São dados fiscais que
 * saem de `config/fiscal.php`, e aceitá-los do navegador seria deixar a nota
 * ser emitida com o código que o cliente digitasse.
 */
class EmitirNotaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // O formulário escolhe ENTRE os perfis definidos no servidor, e
            // nunca os códigos em si. Perfil desconhecido é recusado aqui, e
            // perfil que só sai automático (licenciamento de SaaS) também:
            // emitir à mão o que a cobrança já emite é nota em duplicidade.
            'perfil' => ['required', Rule::in($this->perfisManuais())],

            'tomador_tipo' => ['required', Rule::in(['pf', 'pj'])],
            'tomador_documento' => ['required', 'string', new ValidCpfCnpj],
            'tomador_nome' => ['required', 'string', 'max:255'],
            'tomador_email' => ['nullable', 'email', 'max:255'],

            'tomador_cep' => ['nullable', 'string', 'max:9'],
            'tomador_logradouro' => ['nullable', 'string', 'max:255'],
            'tomador_numero' => ['nullable', 'string', 'max:20'],
            'tomador_complemento' => ['nullable', 'string', 'max:255'],
            'tomador_bairro' => ['nullable', 'string', 'max:255'],
            'tomador_cidade' => ['required', 'string', 'max:255'],
            'tomador_uf' => ['required', 'string', 'size:2'],
            'tomador_ibge' => ['nullable', 'string', 'size:7'],

            // Onde o atendimento foi feito. Vira o local da prestação, que pode
            // divergir do município onde o ISS é devido.
            'local_prestacao_nome' => ['required', 'string', 'max:255'],
            'local_prestacao_ibge' => ['required', 'string', 'size:7'],

            'descricao' => ['required', 'string', 'max:1000'],
            'valor' => ['required', 'numeric', 'min:0.01'],
        ];
    }

    /**
     * Perfis que se emitem à mão.
     *
     * @return array<int, string>
     */
    private function perfisManuais(): array
    {
        return collect(config('fiscal.perfis'))
            ->filter(fn ($perfil) => $perfil['manual'] ?? true)
            ->keys()
            ->all();
    }

    public function messages(): array
    {
        return [
            'perfil.required' => 'Escolha o tipo de serviço da nota.',
            'perfil.in' => 'Esse tipo de serviço não pode ser emitido por aqui.',
            'valor.min' => 'O valor da nota precisa ser maior que zero.',
            'local_prestacao_ibge.required' => 'Escolha onde o atendimento foi feito (o CEP preenche o município).',
            'tomador_cidade.required' => 'A cidade do cliente é obrigatória na nota.',
            'tomador_uf.required' => 'A UF do cliente é obrigatória na nota.',
        ];
    }
}
