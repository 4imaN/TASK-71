<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\StepUpService;
use App\Services\Import\ExportGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ExportController extends Controller
{
    public function __construct(
        private readonly ExportGeneratorService $exporter,
        private readonly StepUpService $stepUp,
    ) {}

    /**
     * POST /api/v1/admin/export
     * Generate and return an export file download. Requires step-up.
     */
    public function generate(Request $request): Response|\Illuminate\Http\JsonResponse
    {
        if (!$this->stepUp->isGranted()) {
            return response()->json(['message' => 'Step-up required.'], 403);
        }

        $data = $request->validate([
            'entity_type' => ['required', 'string', 'in:departments,user_profiles,research_projects,services,users'],
            'format'      => ['required', 'string', 'in:csv,json'],
            'filters'     => ['nullable', 'array'],
            'filters.status'    => ['nullable', 'string'],
            'filters.date_from' => ['nullable', 'date'],
            'filters.date_to'   => ['nullable', 'date'],
        ]);

        $result = $this->exporter->generate(
            entityType: $data['entity_type'],
            format: $data['format'],
            filters: $data['filters'] ?? [],
            admin: $request->user(),
        );

        return response($result['content'], 200, [
            'Content-Type'        => $result['mime_type'],
            'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
        ]);
    }
}
