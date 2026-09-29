<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Verificação de identidade do Azure DevOps
    |--------------------------------------------------------------------------
    |
    | O token de app enviado pela extensão nunca é aceito como prova de
    | identidade por si só. Ele é validado chamando este endpoint do Azure
    | DevOps com o próprio token; a organização/usuário retornados é que
    | definem o tenant, não qualquer campo enviado pelo cliente.
    |
    */
    'devops_connection_data_url' => env('DEVOPS_CONNECTION_DATA_URL', 'https://app.vssps.visualstudio.com/_apis/connectionData'),

    /*
    |--------------------------------------------------------------------------
    | Sessão de backend
    |--------------------------------------------------------------------------
    */
    'session_secret' => env('TIMERSMIT_SESSION_SECRET'),
    'session_ttl_minutes' => (int) env('TIMERSMIT_SESSION_TTL_MINUTES', 60),
];
