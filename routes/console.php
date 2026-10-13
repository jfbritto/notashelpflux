<?php

use Illuminate\Support\Facades\Schedule;

// Enquanto a conta da Notaas tiver UM webhook só, e ele apontar para o
// TreinaEdu, é a reconciliação que fecha as notas desta plataforma: consulta
// o emissor e aplica o desfecho. A cada 5 minutos com janela de 2, porque
// quem emite fica esperando o PDF; é barato, a consulta só acontece quando há
// nota em processando com id do emissor.
//
// Quando a virada do TreinaEdu acontecer (plano 2) e o webhook passar a
// apontar para cá, isto volta a ser rede de segurança e pode relaxar para
// hourly com a janela padrão de 30 minutos.
Schedule::command('notas:reconciliar --minutos=2')->everyFiveMinutes();

// O retorno aos SaaS (EnviarRetorno) é um job com três tentativas e espera
// crescente. As tentativas só existem numa fila de verdade: com
// QUEUE_CONNECTION=sync o envio acontece uma vez, na hora, e a falha fica só
// no log. Não há supervisor na VPS, então quem esvazia a fila `database` é o
// próprio agendador, a cada minuto. Com a fila `sync` este comando não acha
// nada e sai na hora.
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    // Trava de 5 min, não as 24 h padrão: se um processo morrer no meio, a
    // fila volta a andar em minutos, e não no dia seguinte.
    ->withoutOverlapping(5);
