<?php

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\CatalogManager;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Catalog\Enums\PackPlanStatus;
use App\Domain\Catalog\Http\Resources\PackPlanResource;
use App\Domain\Catalog\Http\Resources\PackVersionResource;
use App\Domain\Catalog\Models\PackPlan;
use App\Domain\Catalog\Models\PackVersion;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Back-office administration of the pack catalog. Read access needs packs.view, changes need packs.manage
 * and a recent step-up confirmation.
 */
class PackPlanAdminController
{
    public function __construct(private readonly CatalogManager $catalog) {}

    /**
     * Pack plans with their current published version.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'family' => ['nullable', Rule::enum(PackFamily::class)],
            'status' => ['nullable', Rule::enum(PackPlanStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $plans = PackPlan::query()
            ->with('currentVersion')
            ->when($validated['family'] ?? null, fn ($query, string $family) => $query->where('family', $family))
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $term = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
                $query->where(fn ($inner) => $inner->whereRaw('lower(name) like ?', [$term])->orWhereRaw('lower(code) like ?', [$term]));
            })
            ->orderBy('family')
            ->orderBy('level')
            ->orderBy('code')
            ->paginate($validated['per_page'] ?? 50);

        return PackPlanResource::collection($plans);
    }

    /**
     * One plan with all its versions, published or not.
     */
    public function show(PackPlan $plan): PackPlanResource
    {
        return new PackPlanResource($plan->load(['currentVersion', 'versions']));
    }

    /**
     * Create a plan in DRAFT. It has no terms until a version is added and published.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'family' => ['required', Rule::enum(PackFamily::class)],
            'code' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_-]{1,39}$/'],
            'name' => ['required', 'string', 'max:120'],
            'level' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $plan = $this->catalog->createPlan(
            $this->actor($request),
            PackFamily::from($validated['family']),
            $validated['code'],
            $validated['name'],
            $validated['level'] ?? null,
            $validated['description'] ?? null,
        );

        return (new PackPlanResource($plan->load('currentVersion')))->response()->setStatusCode(201);
    }

    /**
     * Add an unpublished version. The terms are validated according to the family of the plan.
     */
    /**
     * Update the commercial label, level and description, without touching
     * existing contracts, version terms or the permanent plan code.
     */
    public function update(Request $request, PackPlan $plan): PackPlanResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'level' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        return new PackPlanResource($this->catalog->updatePlanMetadata(
            $this->actor($request), $plan, $data['name'],
            $data['level'] ?? null, $data['description'] ?? null, $data['reason'],
        )->load('currentVersion'));
    }

    /**
     * Create a new immutable commercial version.
     */
    public function storeVersion(Request $request, PackPlan $plan): JsonResponse
    {
        $version = $this->catalog->createVersion($this->actor($request), $plan, $request->all());

        return (new PackVersionResource($version))->response()->setStatusCode(201);
    }

    /**
     * Publish a version: it becomes frozen and, as the highest published version, is offered to new subscribers.
     */
    public function publishVersion(Request $request, PackPlan $plan, PackVersion $version): PackVersionResource
    {
        return new PackVersionResource($this->catalog->publishVersion($this->actor($request), $plan, $version));
    }

    /**
     * Discard a version that was never published.
     */
    public function destroyVersion(Request $request, PackPlan $plan, PackVersion $version): JsonResponse
    {
        $this->catalog->discardVersion($this->actor($request), $plan, $version);

        return response()->json(null, 204);
    }

    /**
     * Move a plan through its lifecycle: publish, suspend, retire, archive.
     * Holders of a retired plan keep their pack and its conditions.
     */
    public function transition(Request $request, PackPlan $plan): PackPlanResource
    {
        $validated = $request->validate([
            'to' => ['required', Rule::enum(PackPlanStatus::class)],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $plan = $this->catalog->transition($this->actor($request), $plan, PackPlanStatus::from($validated['to']), $validated['reason']);

        return new PackPlanResource($plan->load('currentVersion'));
    }

    /**
     * Copy a plan under a new code, with its latest terms as an unpublished version.
     */
    public function duplicate(Request $request, PackPlan $plan): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_-]{1,39}$/'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        $copy = $this->catalog->duplicate($this->actor($request), $plan, $validated['code'], $validated['name']);

        return (new PackPlanResource($copy->load(['currentVersion', 'versions'])))->response()->setStatusCode(201);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
