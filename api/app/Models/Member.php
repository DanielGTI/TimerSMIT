<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Identidade Azure DevOps já confirmada (nunca criado a partir de dados
 * afirmados pelo cliente sem passar por DevOpsIdentityVerifier). Implementa
 * Authenticatable apenas para se integrar a Gate/Policy e `$request->user()`
 * — não há login por senha; a sessão vem de SessionTokenService.
 */
class Member extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasFactory;

    /** Horas adicionais podem virar hora extra, banco de horas ou a pagar. */
    public const REGIME_CLT = 'clt';

    /** Horas adicionais só podem ser "a pagar" (sem banco de horas). */
    public const REGIME_PJ = 'pj';

    /** Não controla jornada (ex.: cargo de confiança): não gera hora adicional. */
    public const REGIME_NONE = 'none';

    public const REGIMES = [self::REGIME_CLT, self::REGIME_PJ, self::REGIME_NONE];

    /** Hora extra sem pedido e sem aviso (pessoa de confiança). Só CLT. */
    public const PROFILE_PREAPPROVED = 'preapproved';

    /** Lança normalmente; sem pedido aprovado, a hora fica "sujeita à aprovação". */
    public const PROFILE_STANDARD = 'standard';

    /** Hora fora do expediente sem pedido aprovado vira "hora extra a confirmar". */
    public const PROFILE_RESTRICTED = 'restricted';

    public const PROFILES = [self::PROFILE_PREAPPROVED, self::PROFILE_STANDARD, self::PROFILE_RESTRICTED];

    protected $fillable = [
        'tenant_id',
        'devops_identity_id',
        'display_name',
        'email',
        'hours_regime',
        'overtime_profile',
        'directory_active',
        'directory_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'directory_active' => 'boolean',
            'directory_synced_at' => 'datetime',
        ];
    }

    /** Perfil de hora extra em vigor: só CLT tem perfil; os demais regimes seguem o padrão. */
    public function overtimeProfile(): string
    {
        if (($this->hours_regime ?? self::REGIME_CLT) !== self::REGIME_CLT) {
            return self::PROFILE_STANDARD;
        }

        return in_array($this->overtime_profile, self::PROFILES, true) ? $this->overtime_profile : self::PROFILE_STANDARD;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }
}
