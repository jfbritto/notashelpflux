<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListaDeNotasTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitante_nao_ve_a_lista(): void
    {
        $this->get(route('notas.index'))->assertRedirect(route('login'));
    }

    public function test_a_lista_mostra_situacao_e_os_links_de_documento(): void
    {
        Nota::factory()->emitida()->create([
            'tomador_nome' => 'Clínica Exemplo Ltda',
            'pdf_url' => 'https://storage.notaas.test/n.pdf',
            'xml_url' => 'https://storage.notaas.test/n.xml',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('notas.index'))
            ->assertOk()
            ->assertSee('Clínica Exemplo Ltda')
            ->assertSee('Emitida')
            ->assertSee('https://storage.notaas.test/n.pdf');
    }

    /**
     * Nota recusada não pode se passar por pendente. No TreinaEdu isso
     * escondeu uma nota parada por um dia: quem olhava a tela para entender o
     * que houve não descobria nada.
     */
    public function test_nota_com_erro_nao_aparece_como_em_emissao(): void
    {
        Nota::factory()->create(['status' => 'erro', 'erro' => 'E0120 inscricao do prestador']);

        $this->actingAs(User::factory()->create())
            ->get(route('notas.index'))
            ->assertSee('Não emitida')
            ->assertDontSee('Em emissão');
    }

    /**
     * A emissora vê as notas dela; o admin acompanha a operação inteira. É a
     * diferença entre emitir e administrar.
     */
    public function test_emissor_ve_so_as_manuais_e_admin_ve_todas(): void
    {
        Nota::factory()->create(['origem' => 'manual', 'tomador_nome' => 'Paciente Particular']);
        Nota::factory()->create([
            'origem' => 'treinaedu', 'referencia_externa' => 'inv-1', 'tomador_nome' => 'Empresa Assinante',
        ]);

        $this->actingAs(User::factory()->create(['papel' => 'emissor']))
            ->get(route('notas.index'))
            ->assertSee('Paciente Particular')
            ->assertDontSee('Empresa Assinante');

        $this->actingAs(User::factory()->create(['papel' => 'admin']))
            ->get(route('notas.index'))
            ->assertSee('Paciente Particular')
            ->assertSee('Empresa Assinante');
    }

    /**
     * O que resolve o paciente recorrente sem construir cadastro de pacientes.
     */
    public function test_repetir_nota_abre_o_formulario_com_o_cliente_preenchido(): void
    {
        $nota = Nota::factory()->create([
            'tomador_nome' => 'Clínica Exemplo Ltda',
            'tomador_documento' => '11222333000181',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('notas.create', ['repetir' => $nota->id]))
            ->assertOk()
            ->assertSee('Clínica Exemplo Ltda')
            ->assertSee('Repetindo a nota', false);
    }

    /** Repetir só vale para nota manual: as dos SaaS vêm da cobrança deles. */
    public function test_nota_de_saas_nao_e_repetivel(): void
    {
        $nota = Nota::factory()->create([
            'origem' => 'treinaedu', 'referencia_externa' => 'inv-1', 'tomador_nome' => 'Empresa Assinante',
        ]);

        $this->actingAs(User::factory()->create(['papel' => 'admin']))
            ->get(route('notas.create', ['repetir' => $nota->id]))
            ->assertOk()
            ->assertDontSee('Repetindo a nota', false);
    }
}
