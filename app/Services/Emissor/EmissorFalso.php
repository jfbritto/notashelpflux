<?php

namespace App\Services\Emissor;

use App\Models\Nota;

/**
 * O emissor dos testes e do E2E. Nunca toca a rede.
 *
 * Ele existe por um motivo específico deste domínio: teste que emite nota de
 * verdade é problema fiscal, não bug. Nota emitida por engano precisa ser
 * cancelada na prefeitura, dentro de prazo, e aparece na apuração de imposto.
 */
class EmissorFalso implements Emissor
{
    /** @var array<string, mixed>|null */
    private static ?array $respostaDaConsulta = null;

    /** @var array<string, mixed>|null */
    private static ?array $respostaDoCancelamento = null;

    private static bool $envioProibido = false;

    /**
     * Faz a próxima consulta responder o que o teste quiser.
     *
     * @param  array<string, mixed>  $retorno
     */
    public static function responderConsultaCom(array $retorno): void
    {
        static::$respostaDaConsulta = $retorno;
    }

    /**
     * Faz `enviar()` explodir.
     *
     * Serve para provar que a reconciliação CONSULTA e nunca emite. Sem isso
     * aquele teste passaria por coincidência (ninguém chamou `enviar`) em vez
     * de por garantia (chamar seria erro).
     */
    public static function proibirEnvio(): void
    {
        static::$envioProibido = true;
    }

    /** @param  array<string, mixed>  $retorno */
    public static function responderCancelamentoCom(array $retorno): void
    {
        static::$respostaDoCancelamento = $retorno;
    }

    public static function esquecer(): void
    {
        static::$respostaDaConsulta = null;
        static::$respostaDoCancelamento = null;
        static::$envioProibido = false;
    }

    public function enviar(Nota $nota): array
    {
        if (static::$envioProibido) {
            throw new \RuntimeException('Este caminho não pode emitir: geraria uma segunda nota.');
        }

        // Grava o id como o emissor real faz. Sem ele, webhook e reconciliação
        // não encontram a nota.
        $nota->update(['notaas_invoice_id' => 'fake-'.$nota->id]);

        return ['status' => 'processando'];
    }

    public function consultar(string $idNoEmissor): array
    {
        return static::$respostaDaConsulta ?? ['status' => 'processando'];
    }

    public function cancelar(string $idNoEmissor, string $motivo): array
    {
        return static::$respostaDoCancelamento ?? ['status' => 'cancelada'];
    }
}
