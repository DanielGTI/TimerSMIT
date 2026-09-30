<?php

namespace App\Services;

use RuntimeException;

/**
 * Uma organização já registrada (por `devops_organization_id`) foi
 * reivindicada por um tenant do Azure AD diferente do que a criou. Isso
 * nunca deve acontecer em uso legítimo (a organização não muda de tenant
 * Entra) — trata-se de nome de organização forjado/reaproveitado por um
 * chamador de outro diretório. Negar, nunca fundir ou sobrescrever.
 */
class TenantOwnershipMismatchException extends RuntimeException {}
