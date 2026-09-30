<?php

namespace App\Http\Controllers;

use App\Services\ActivityTypeService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;

class ActivityTypeController extends Controller
{
    public function index(TenantContext $tenantContext, ActivityTypeService $activityTypes): JsonResponse
    {
        return response()->json(
            $activityTypes->listEnabled($tenantContext->tenant())->map(fn ($type) => [
                'id' => (string) $type->id,
                'name' => $type->name,
                'color' => $type->color,
                'defaultBillable' => $type->is_default_billable,
            ])->values()
        );
    }
}
