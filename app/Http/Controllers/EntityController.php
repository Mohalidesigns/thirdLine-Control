<?php

namespace App\Http\Controllers;

use App\Http\Requests\EntityRequest;
use App\Models\Entity;
use App\Models\OrganisationUnit;
use App\Models\User;
use App\Services\EntityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EntityController extends Controller
{
    public function __construct(private EntityService $entities) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Entity::class);

        return Inertia::render('Entities/Index', [
            'tree' => $this->entities->tree(),
            'accessibleIds' => $request->user()->accessibleEntityIds(),
            'units' => OrganisationUnit::orderBy('name')->get(['id', 'name']),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'grants' => Entity::with(['grantedUsers:id,name'])->get(['id', 'legal_name'])
                ->map(fn ($entity) => [
                    'id' => $entity->id,
                    'legal_name' => $entity->legal_name,
                    'users' => $entity->grantedUsers->map->only(['id', 'name']),
                ]),
            'entityTypes' => Entity::ENTITY_TYPES,
            'consolidationMethods' => Entity::CONSOLIDATION_METHODS,
            'statuses' => Entity::STATUSES,
        ]);
    }

    public function store(EntityRequest $request): RedirectResponse
    {
        $this->authorize('create', Entity::class);

        $this->entities->create($request->validated(), $request->user());

        return back()->with('success', 'Entity created.');
    }

    public function update(EntityRequest $request, Entity $entity): RedirectResponse
    {
        $this->authorize('update', $entity);

        $this->entities->update($entity, $request->validated());

        return back()->with('success', 'Entity updated.');
    }

    public function grantAccess(Request $request, Entity $entity): RedirectResponse
    {
        $this->authorize('grantAccess', $entity);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->entities->grantAccess(
            $entity,
            User::findOrFail($data['user_id']),
            $request->user(),
            $data['note'] ?? null,
        );

        return back()->with('success', 'Access granted.');
    }

    public function revokeAccess(Request $request, Entity $entity, User $user): RedirectResponse
    {
        $this->authorize('grantAccess', $entity);

        $this->entities->revokeAccess($entity, $user, $request->user());

        return back()->with('success', 'Access revoked.');
    }
}
