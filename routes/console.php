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
