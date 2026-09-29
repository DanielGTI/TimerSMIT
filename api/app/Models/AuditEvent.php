<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class AuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'project_id',
        'actor_member_id',
        'action',
        'subject_type',
        'subject_id',
        'context',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Trilha de auditoria é append-only (FR-011): nunca permitir update/delete
     * pelo Eloquent, mesmo que algum código chame save()/delete() por engano.
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new RuntimeException('Eventos de auditoria não podem ser alterados.');
        }

        return parent::save($options);
    }

    public function delete(): bool
    {
        throw new RuntimeException('Eventos de auditoria não podem ser excluídos.');
    }
}
