<?php

namespace App\Domain\Catalog;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Catalog\Enums\PackPlanStatus;
use App\Domain\Catalog\Exceptions\CatalogException;
use App\Domain\Catalog\Models\PackPlan;
use App\Domain\Catalog\Models\PackVersion;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Administration of the pack catalog: plans with a lifecycle, immutable versions, duplication.
 * Every operation is audited in the same transaction.
 */
class CatalogManager
{
    public function __construct(
        private readonly PackVersionValidator $validator,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function createPlan(User $actor, PackFamily $family, string $code, string $name, ?int $level, ?string $description): PackPlan
    {
        try {
            return DB::transaction(function () use ($actor, $family, $code, $name, $level, $description): PackPlan {
                $plan = PackPlan::query()->create([
                    'family' => $family,
                    'code' => $code,
                    'name' => $name,
                    'level' => $level,
                    'description' => $description,
                    'status' => PackPlanStatus::Draft,
                    'created_by' => $actor->id,
                ]);

                $this->auditLogger->record('packs.plan_created', $plan, after: [
                    'family' => $family->value,
                    'code' => $code,
                    'name' => $name,
                    'level' => $level,
                ], actor: $actor);

                return $plan;
            });
        } catch (UniqueConstraintViolationException) {
            throw CatalogException::codeTaken($code);
        }
    }

    /**
     * Adds an unpublished version. It is not offered to anyone until it is published.
     *
     * @param  array<string, mixed>  $terms  raw API input, validated according to the family of the plan
     */
    public function updatePlanMetadata(User $actor, PackPlan $plan, string $name, ?int $level, ?string $description, string $reason): PackPlan
    {
        $this->assertMutable($plan);

        return DB::transaction(function () use ($actor, $plan, $name, $level, $description, $reason): PackPlan {
            $plan = PackPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->assertMutable($plan);
            $before = $plan->only(['name', 'level', 'description']);
            $plan->forceFill([
                'name' => trim($name),
                'level' => $level,
                'description' => $description !== null ? trim($description) : null,
            ])->save();

            $this->auditLogger->record(
                'packs.plan_metadata_updated',
                $plan,
                before: $before,
                after: $plan->only(['name', 'level', 'description']),
                reason: $reason,
                actor: $actor,
            );
            return $plan->refresh();
        });
    }

    public function createVersion(User $actor, PackPlan $plan, array $terms): PackVersion
    {
        $this->assertMutable($plan);
        $attributes = $this->validator->validate($plan->family, $terms);

        return DB::transaction(function () use ($actor, $plan, $attributes): PackVersion {
            PackPlan::query()->lockForUpdate()->findOrFail($plan->id);

            $next = (int) PackVersion::query()->where('pack_plan_id', $plan->id)->max('version') + 1;

            $version = PackVersion::query()->create([
                ...$attributes,
                'pack_plan_id' => $plan->id,
                'version' => $next,
                'currency' => 'XOF',
                'created_by' => $actor->id,
                'created_at' => now(),
            ]);

            $this->auditLogger->record('packs.version_created', $plan, after: $this->describe($version), reason: $version->reason, actor: $actor);

            return $version;
        });
    }

    public function publishVersion(User $actor, PackPlan $plan, PackVersion $version): PackVersion
    {
        $this->assertOwnership($plan, $version);
        $this->assertMutable($plan);

        if ($version->isPublished()) {
            throw CatalogException::versionAlreadyPublished();
        }

        return DB::transaction(function () use ($actor, $plan, $version): PackVersion {
            $version->forceFill(['published_at' => now()])->save();

            $this->auditLogger->record('packs.version_published', $plan, after: $this->describe($version), actor: $actor);

            return $version->refresh();
        });
    }

    public function discardVersion(User $actor, PackPlan $plan, PackVersion $version): void
    {
        $this->assertOwnership($plan, $version);

        if ($version->isPublished()) {
            throw CatalogException::versionAlreadyPublished();
        }

        DB::transaction(function () use ($actor, $plan, $version): void {
            $this->auditLogger->record('packs.version_discarded', $plan, before: $this->describe($version), actor: $actor);
            $version->delete();
        });
    }

    public function transition(User $actor, PackPlan $plan, PackPlanStatus $target, string $reason): PackPlan
    {
        return DB::transaction(function () use ($actor, $plan, $target, $reason): PackPlan {
            $plan = PackPlan::query()->lockForUpdate()->findOrFail($plan->id);

            if (! $plan->status->canTransitionTo($target)) {
                throw CatalogException::invalidTransition($plan->status->value, $target->value);
            }

            if ($target === PackPlanStatus::Published && ! PackVersion::query()->where('pack_plan_id', $plan->id)->whereNotNull('published_at')->exists()) {
                throw CatalogException::noPublishedVersion();
            }

            $before = $plan->status;
            $plan->forceFill([
                'status' => $target,
                'published_at' => $target === PackPlanStatus::Published ? ($plan->published_at ?? now()) : $plan->published_at,
            ])->save();

            $this->auditLogger->record(
                'packs.plan_'.$target->value,
                $plan,
                before: ['status' => $before->value],
                after: ['status' => $target->value],
                reason: $reason,
                actor: $actor,
            );

            return $plan;
        });
    }

    /**
     * Copies a plan under a new code, with its current terms as an unpublished first version.
     */
    public function duplicate(User $actor, PackPlan $source, string $code, string $name): PackPlan
    {
        $template = $source->versions()->first();

        return DB::transaction(function () use ($actor, $source, $code, $name, $template): PackPlan {
            $copy = $this->createPlan($actor, $source->family, $code, $name, $source->level, $source->description);

            if ($template instanceof PackVersion) {
                PackVersion::query()->create([
                    'pack_plan_id' => $copy->id,
                    'version' => 1,
                    'price_xof' => $template->price_xof,
                    'currency' => $template->currency,
                    'period_unit' => $template->period_unit,
                    'period_every' => $template->period_every,
                    'period_cycles' => $template->period_cycles,
                    'quota_per_period' => $template->quota_per_period,
                    'cumulative' => $template->cumulative,
                    'end_of_term' => $template->end_of_term,
                    'min_operation' => $template->min_operation,
                    'max_operation' => $template->max_operation,
                    'eligible_person_types' => $template->eligible_person_types,
                    'rules' => $template->rules,
                    'reason' => "Copie de {$source->code} v{$template->version}",
                    'created_by' => $actor->id,
                    'created_at' => now(),
                ]);
            }

            $this->auditLogger->record('packs.plan_duplicated', $copy, after: ['source_plan_id' => $source->id, 'source_code' => $source->code], actor: $actor);

            return $copy;
        });
    }

    private function assertMutable(PackPlan $plan): void
    {
        if ($plan->status === PackPlanStatus::Archived) {
            throw CatalogException::planArchived();
        }
    }

    private function assertOwnership(PackPlan $plan, PackVersion $version): void
    {
        if ($version->pack_plan_id !== $plan->id) {
            throw CatalogException::versionOfOtherPlan();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(PackVersion $version): array
    {
        return [
            'version_id' => $version->id,
            'version' => $version->version,
            'price_xof' => $version->price_xof,
            'period_unit' => $version->period_unit?->value,
            'period_every' => $version->period_every,
            'period_cycles' => $version->period_cycles,
            'quota_per_period' => $version->quota_per_period,
            'cumulative' => $version->cumulative,
            'end_of_term' => $version->end_of_term?->value,
            'published' => $version->isPublished(),
        ];
    }
}
