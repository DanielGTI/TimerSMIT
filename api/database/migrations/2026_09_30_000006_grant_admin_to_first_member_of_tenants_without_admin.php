<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Organizações conectadas antes do bootstrap de admin (a primeira pessoa
     * de uma organização nova vira admin — IdentityProvisioningService) ficaram
     * sem ninguém com papel, e a negação por padrão bloqueia todo mundo.
     * Aplica a mesma regra retroativamente: o membro mais antigo de cada
     * organização sem admin passa a ser admin. Idempotente.
     */
    public function up(): void
    {
        $tenantIds = DB::table('tenants')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('role_assignments')
                    ->whereColumn('role_assignments.tenant_id', 'tenants.id')
                    ->where('role_assignments.role', 'admin');
            })
            ->pluck('id');

        foreach ($tenantIds as $tenantId) {
            $memberId = DB::table('members')->where('tenant_id', $tenantId)->orderBy('id')->value('id');

            if ($memberId === null) {
                continue;
            }

            DB::table('role_assignments')->insert([
                'tenant_id' => $tenantId,
                'member_id' => $memberId,
                'project_id' => null,
                'role' => 'admin',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Sem reversão: não dá para distinguir esses admins dos criados depois.
    }
};
