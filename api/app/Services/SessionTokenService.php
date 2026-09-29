<?php

namespace App\Services;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Carbon;
use RuntimeException;
use UnexpectedValueException;

/**
 * Sessão própria do backend (não é o token do Azure DevOps). Emitida somente
 * depois que DevOpsIdentityVerifier confirma a identidade junto ao host.
 * Curta duração de propósito: a extensão pode sempre pedir um novo token de
 * app ao SDK e trocar por uma nova sessão.
 */
class SessionTokenService
{
    private const ALGO = 'HS256';

    public function issue(int $tenantId, int $memberId): SessionToken
    {
        $secret = $this->secret();
        $ttlMinutes = (int) config('timersmit.session_ttl_minutes', 60);
        $now = Carbon::now();
        $expiresAt = $now->copy()->addMinutes($ttlMinutes);

        $token = JWT::encode([
            'iat' => $now->timestamp,
            'exp' => $expiresAt->timestamp,
            'tenant_id' => $tenantId,
            'member_id' => $memberId,
        ], $secret, self::ALGO);

        return new SessionToken($token, $expiresAt, $tenantId, $memberId);
    }

    public function parse(string $token): SessionTokenClaims
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret(), self::ALGO));
        } catch (ExpiredException $exception) {
            throw new InvalidSessionTokenException('Sessão expirada.', previous: $exception);
        } catch (SignatureInvalidException|UnexpectedValueException $exception) {
            throw new InvalidSessionTokenException('Sessão inválida.', previous: $exception);
        }

        return new SessionTokenClaims(
            tenantId: (int) $decoded->tenant_id,
            memberId: (int) $decoded->member_id,
        );
    }

    private function secret(): string
    {
        $secret = config('timersmit.session_secret');

        if (! $secret) {
            throw new RuntimeException('TIMERSMIT_SESSION_SECRET não configurado.');
        }

        return $secret;
    }
}
