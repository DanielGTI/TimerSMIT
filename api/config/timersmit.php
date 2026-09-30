<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Verificação de identidade do Azure DevOps
    |--------------------------------------------------------------------------
    |
    | Segredo simétrico (HS256) exclusivo da extensão publicada, usado para
    | validar localmente o JWT de `SDK.getAppToken()` — sem nenhuma chamada
    | de rede ao Azure DevOps. Obtido no Marketplace: extensão → "Certificate".
    | Muda se os `scopes` do manifesto mudarem; buscar um novo nesse caso.
    |
    */
    'extension_secret' => env('AZURE_DEVOPS_EXTENSION_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Sessão de backend
    |--------------------------------------------------------------------------
    */
    'session_secret' => env('TIMERSMIT_SESSION_SECRET'),
    'session_ttl_minutes' => (int) env('TIMERSMIT_SESSION_TTL_MINUTES', 60),
];
