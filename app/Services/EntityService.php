<?php

namespace App\Services;

use App\Models\Entity;
use App\Models\User;
use App\Support\TenantCache;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The legal-entity register for banking groups and holding companies
 * (16.1). Entities anchor an organisation-unit subtree; access to a
 * subsidiary's data is granted per user, per entity, by a named person —
 * never implied by seniority (R2).
 */
class EntityService
{
    public function create(array $data, User $actor): Entity
    {
        $this->assertParentValid($data, null);

        $entity = Entity::create([
            ...$data,
            'reference' => Entity::nextReference('ENT', withYear: false),
            'created_by' => $actor->id,
        ]);

        $entity->auditAction('entity-created', null, ['legal_name' => $entity->legal_name]);

        $this->forgetRollup($entity->tenant_id);

        return $entity;
    }

    public function update(Entity $entity, array $data): Entity
    {
        $this->assertParentValid($data, $entity);

        $before = $entity->only(array_keys($data));
        $entity->update($data);

        $entity->auditAction('entity-updated', $before, $entity->only(array_keys($data)));

        $this->forgetRollup($entity->tenant_id);

        return $entity;
    }

    /** Grant a user sight of this entity's data (16.1 business rule). */
    public function grantAccess(Entity $entity, User $user, User $grantor, ?string $note = null): void
    {
        if ($user->tenant_id !== $entity->tenant_id) {
            throw ValidationException::withMessages(['user' => 'The user belongs to another tenant.']);
        }

        $entity->grantedUsers()->syncWithoutDetaching([
            $user->id => ['granted_by' => $grantor->id, 'note' => $note],
        ]);

        $entity->auditAction('entity-access-granted', null, [
            'user' => $user->name, 'granted_by' => $grantor->name, 'note' => $note,
        ]);
    }

    public function revokeAccess(Entity $entity, User $user, User $revoker): void
    {
        $entity->grantedUsers()->detach($user->id);

        $entity->auditAction('entity-access-revoked', ['user' => $user->name], [
            'revoked_by' => $revoker->name,
        ]);
    }

    /**
     * The entities this user may see: exactly those explicitly granted.
     * Absence of a grant is denial — no role, including System
     * Administrator, widens this (R2).
     */
    public function accessibleEntities(User $user): Collection
    {
        return Entity::query()
            ->whereHas('grantedUsers', fn ($q) => $q->where('users.id', $user->id))
            ->with('parent:id,legal_name')
            ->orderBy('legal_name')
            ->get();
    }

    /** Parent/child tree of all entities in the tenant, for the register. */
    public function tree(): Collection
    {
        $entities = Entity::query()
            ->with(['parent:id,legal_name', 'organisationUnit:id,name'])
            ->withCount('children')
            ->orderBy('legal_name')
            ->get();

        return $entities->whereNull('parent_entity_id')->values()
            ->map(fn (Entity $root) => $this->branch($root, $entities));
    }

    private function branch(Entity $node, Collection $all): array
    {
        return [
            'entity' => $node,
            'children' => $all->where('parent_entity_id', $node->id)->values()
                ->map(fn (Entity $child) => $this->branch($child, $all))->all(),
        ];
    }

    private function assertParentValid(array $data, ?Entity $entity): void
    {
        $parentId = $data['parent_entity_id'] ?? null;

        if (! $parentId) {
            return;
        }

        if ($entity && (int) $parentId === $entity->id) {
            throw ValidationException::withMessages(['parent_entity_id' => 'An entity cannot be its own parent.']);
        }

        // Reject a cycle: walking up from the proposed parent must never
        // reach the entity being saved.
        $cursor = Entity::find($parentId);

        while ($cursor) {
            if ($entity && $cursor->id === $entity->id) {
                throw ValidationException::withMessages(['parent_entity_id' => 'This parent would create a cycle.']);
            }

            $cursor = $cursor->parent;
        }
    }

    private function forgetRollup(?int $tenantId): void
    {
        TenantCache::forget($tenantId, ConsolidationService::CACHE_SUFFIX);
    }
}
