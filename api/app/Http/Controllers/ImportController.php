<?php

namespace App\Http\Controllers;

use App\Services\SevenPaceImportService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Importação de lançamentos de outra ferramenta (só administradores). O
 * arquivo vem em base64 no JSON: a mesma chamada simula (`dryRun`) e importa.
 */
class ImportController extends Controller
{
    /** 10 MB de planilha (em base64 fica ~13,4 MB). */
    private const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly SevenPaceImportService $sevenPace,
        private readonly TenantContext $tenantContext,
    ) {}

    public function sevenPace(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'string'],
            'dryRun' => ['required', 'boolean'],
            'personMap' => ['nullable', 'array'],
            'personMap.*' => ['nullable', 'string', 'max:20'],
            'projectIds' => ['nullable', 'array'],
            'projectIds.*' => ['string', 'regex:/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/'],
        ], [
            'projectIds.*.regex' => 'Identificador de projeto do Azure DevOps inválido.',
        ]);

        $content = base64_decode($data['file'], true);
        if ($content === false || $content === '' || strlen($content) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'Envie a planilha .xlsx exportada do 7pace (até 10 MB).']);
        }

        $path = tempnam(sys_get_temp_dir(), 'import-7pace-');
        file_put_contents($path, $content);

        try {
            return response()->json($this->sevenPace->run(
                $this->tenantContext->tenant(),
                $this->tenantContext->member(),
                $path,
                array_map(fn ($value) => (string) $value, $data['personMap'] ?? []),
                array_map(fn ($value) => strtolower((string) $value), $data['projectIds'] ?? []),
                (bool) $data['dryRun'],
            ));
        } finally {
            @unlink($path);
        }
    }
}
