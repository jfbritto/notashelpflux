<?php

use Illuminate\Support\Facades\Schedule;

// A emissão é assíncrona e quem fecha a nota é o webhook do emissor. Webhook
// perdido deixava a nota em "processando" para sempre, sem erro e sem ninguém
// saber. De hora em hora porque nota fiscal tem prazo de competência, e
// esperar o dia seguinte é esperar demais.
Schedule::command('notas:reconciliar')->hourly();
