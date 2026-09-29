<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 409 — estado atual do recurso não permite a operação (ex.: timer já
 * ativo, semana já enviada/aprovada). Ver FR-002/FR-006/FR-007.
 */
class ConflictException extends RuntimeException {}
