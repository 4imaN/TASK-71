<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ImportConflict;
use App\Models\ImportFieldMappingTemplate;
use App\Models\ImportJob;
use App\Services\Import\ConflictResolutionService;
use App\Services\Import\ImportProcessorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ImportController extends Controller
{
    public function __construct(
        private readonly ImportProcessorService $processor,
        private readonly ConflictResolutionService $conflictResolver,
    ) {}

    /**
     * GET /api/v1/admin/import
     * List import jobs with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ImportJob::query()->latest();

        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->input('entity_type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $jobs = $query->paginate(20);

        return response()->json($jobs);
    }

    /**
     * POST /api/v1/admin/import
     * Create and process a new import job.
     * Accepts either a multipart file upload or an inline 'content' string.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_type'                 => ['required', 'string', 'in:departments,user_profiles,research_projects,services,users'],
            'source_system'               => ['nullable', 'string', 'max:40'],
            'file_format'                 => ['required', 'string', 'in:csv,json'],
            'conflict_resolution_strategy'=> ['nullable', 'string', 'in:prefer_newest,admin_override,pending'],
            'field_mapping'               => ['nullable', 'array'],
            'last_sync_timestamp'         => ['nullable', 'date'],
            'content'                     => ['nullable', 'string'],
            'file'                        => ['nullable', 'file'],
        ]);

        // Resolve content
        if ($request->hasFile('file')) {
            $rawContent      = $request->file('file')->get();
            $originalFilename = $request->file('file')->getClientOriginalName();
        } elseif (!empty($data['content'])) {
            $rawContent      = $data['content'];
            $originalFilename = null;
        } else {
            return response()->json(['message' => 'Either file or content is required.'], 422);
        }

        $job = ImportJob::create([
            'uuid'                          => (string) Str::uuid(),
            'entity_type'                   => $data['entity_type'],
            'source_system'                 => $data['source_system'] ?? null,
            'file_format'                   => $data['file_format'],
            'original_filename'             => $originalFilename,
            'field_mapping'                 => $data['field_mapping'] ?? null,
            'status'                        => 'pending',
            'last_sync_timestamp'           => $data['last_sync_timestamp'] ?? null,
            'conflict_resolution_strategy'  => $data['conflict_resolution_strategy'] ?? 'pending',
            'created_by'                    => $request->user()->id,
        ]);

        // Process synchronously
        $this->processor->process($job, $rawContent, $request->user());

        return response()->json(['job' => $job->refresh()], 201);
    }

    /**
     * GET /api/v1/admin/import/{id}
     * Show a job with its conflicts.
     */
    public function show(int $id): JsonResponse
    {
        $job = ImportJob::with('conflicts')->findOrFail($id);

        return response()->json(['job' => $job]);
    }

    /**
     * POST /api/v1/admin/import/{id}/resolve
     * Admin resolves a single conflict.
     */
    public function resolveConflict(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'conflict_id'     => ['required', 'integer'],
            'resolution'      => ['required', 'string', 'in:prefer_newest,admin_override'],
            'resolved_record' => ['nullable', 'array'],
        ]);

        $job      = ImportJob::findOrFail($id);
        $conflict = ImportConflict::where('import_job_id', $job->id)
            ->findOrFail($data['conflict_id']);

        $resolved = $this->conflictResolver->adminResolve(
            conflict: $conflict,
            resolution: $data['resolution'],
            resolvedRecord: $data['resolved_record'] ?? [],
            admin: $request->user(),
        );

        return response()->json(['conflict' => $resolved]);
    }

    /**
     * POST /api/v1/admin/import/{id}/reprocess
     * Apply all resolved conflicts for a job via the entity strategy, then
     * update the job status.  This is the REST equivalent of the Livewire
     * ImportJobDetailComponent::reprocess() action.
     */
    public function reprocess(Request $request, int $id): JsonResponse
    {
        $job = ImportJob::findOrFail($id);

        $this->processor->reprocessResolvedConflicts($job, $request->user());

        return response()->json(['job' => $job->refresh()]);
    }

    /**
     * GET /api/v1/admin/import/templates
     * List all field mapping templates.
     */
    public function listTemplates(Request $request): JsonResponse
    {
        $query = ImportFieldMappingTemplate::query();

        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->input('entity_type'));
        }

        if ($request->filled('source_system')) {
            $query->where('source_system', $request->input('source_system'));
        }

        $templates = $query->get();

        return response()->json(['templates' => $templates]);
    }

    /**
     * POST /api/v1/admin/import/templates
     * Create a new field mapping template.
     */
    public function createTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:150'],
            'entity_type'   => ['required', 'string', 'max:60'],
            'source_system' => ['nullable', 'string', 'max:40'],
            'field_mapping' => ['required', 'array'],
        ]);

        $template = ImportFieldMappingTemplate::create([
            'name'          => $data['name'],
            'entity_type'   => $data['entity_type'],
            'source_system' => $data['source_system'] ?? null,
            'field_mapping' => $data['field_mapping'],
            'created_by'    => $request->user()->id,
        ]);

        return response()->json(['template' => $template], 201);
    }
}
