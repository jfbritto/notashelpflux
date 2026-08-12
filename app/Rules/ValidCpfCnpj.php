<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida CPF (11 dígitos) ou CNPJ (14 dígitos) pelo dígito verificador.
 * Aceita o valor com ou sem máscara. Essencial para o cadastro fiscal: um
 * documento inválido derruba a emissão da NFS-e lá na frente.
 */
class ValidCpfCnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        $valid = match (strlen($digits)) {
            11 => $this->isValidCpf($digits),
            14 => $this->isValidCnpj($digits),
            default => false,
        };

        if (!$valid) {
            $fail('Informe um CPF ou CNPJ válido.');
        }
    }

    private function isValidCpf(string $cpf): bool
    {
        // Sequências repetidas (000..., 111...) passam no cálculo mas são inválidas.
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $cpf[$i] * (($t + 1) - $i);
            }
            $digit = ((10 * $sum) % 11) % 10;
            if ($digit !== (int) $cpf[$t]) {
                return false;
            }
        }

        return true;
    }

    private function isValidCnpj(string $cnpj): bool
    {
        if (preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        $weights1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $weights2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        foreach ([[12, $weights1], [13, $weights2]] as [$len, $weights]) {
            $sum = 0;
            for ($i = 0; $i < $len; $i++) {
                $sum += (int) $cnpj[$i] * $weights[$i];
            }
            $rest = $sum % 11;
            $digit = $rest < 2 ? 0 : 11 - $rest;
            if ($digit !== (int) $cnpj[$len]) {
                return false;
            }
        }

        return true;
    }
}
