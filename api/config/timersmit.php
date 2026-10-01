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
    | Diretórios (Entra ID) autorizados
    |--------------------------------------------------------------------------
    |
    | Uso interno: só identidades de um destes diretórios (claim `tid` do
    | token assinado, não forjável) abrem sessão — qualquer outra recebe 403
    | antes de criar organização ou pessoa. Lista separada por vírgula em
    | TIMERSMIT_ALLOWED_AAD_TENANTS; o padrão é o diretório da SMIT
    | (smit.net.br). Variável definida e vazia nega todos.
    |
    */
    'allowed_aad_tenants' => array_values(array_filter(array_map(
        fn (string $id) => strtolower(trim($id)),
        explode(',', (string) env('TIMERSMIT_ALLOWED_AAD_TENANTS', '5517d73c-0aed-49c1-9d7e-0a38889a4fc5')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sessão de backend
    |--------------------------------------------------------------------------
    */
    'session_secret' => env('TIMERSMIT_SESSION_SECRET'),
    'session_ttl_minutes' => (int) env('TIMERSMIT_SESSION_TTL_MINUTES', 60),
];
