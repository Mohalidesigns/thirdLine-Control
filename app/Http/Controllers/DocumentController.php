<?php

namespace App\Http\Controllers;

use App\Http\Requests\DocumentRequest;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

class DocumentController extends Controller
{
    public function __construct(private DocumentService $documentService) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Document::class);

        $user = $request->user();

        $query = Document::query()
            ->with(['folder:id,name,path', 'owner:id,name', 'approver:id,name'])
            ->when($request->folder_id, fn ($q, $f) => $q->where('folder_id', $f))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->document_type, fn ($q, $t) => $q->where('document_type', $t))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$s}%")
                ->orWhere('reference', 'like', "%{$s}%")));

        // Confidential documents leave the list unless the user's roles match
        // (query-level enforcement, mirrored in DocumentPolicy).
        $roleIds = $user->roles->pluck('id');
        $query->where(function ($q) use ($roleIds, $user) {
            $q->where('is_confidential', false)
                ->orWhere('owner_id', $user->id);

            foreach ($roleIds as $roleId) {
                $q->orWhereJsonContains('access_role_ids', $roleId);
            }
        });

        // Non-authors see only published/superseded material.
        if (! $user->can('create documents') && ! $user->can('approve documents')) {
            $query->whereIn('status', ['Published', 'Superseded', 'Archived']);
        }

        return Inertia::render('Documents/Index', [
            'documents' => $query->orderByDesc('updated_at')->paginate(15)->withQueryString(),
            'folderTree' => $this->documentService->folderTree(),
            'filters' => $request->only(['folder_id', 'status', 'document_type', 'search']),
            'statuses' => Document::STATUSES,
            'documentTypes' => Document::TYPES,
            'reviewDueCount' => Document::reviewDue()->count(),
            'can' => [
                'create' => $user->can('create', Document::class),
                'manageFolders' => $user->can('manage document-folders'),
            ],
            'roles' => Role::orderBy('name')->get(['id', 'name']),
            'users' => User::tenantPicker()->get(['id', 'name']),
        ]);
    }

    public function store(DocumentRequest $request)
    {
        $this->authorize('create', Document::class);

        $document = $this->documentService->create(
            collect($request->validated())->except(['file', 'change_summary'])->all(),
            $request->file('file'),
            $request->user(),
        );

        return redirect()->route('documents.show', $document)
            ->with('success', "Document {$document->reference} created as draft.");
    }

    public function show(Document $document)
    {
        $this->authorize('view', $document);

        $user = auth()->user();

        return Inertia::render('Documents/Show', [
            'document' => $document->load([
                'folder:id,name,path', 'owner:id,name', 'approver:id,name',
                'versions.creator:id,name', 'versions.approver:id,name',
                'supersedes:id,reference,title,status',
                'supersededBy:id,reference,title,status,supersedes_document_id',
            ]),
            'can' => [
                'update' => $user->can('update', $document),
                'submit' => $user->can('submit', $document),
                'approve' => $user->can('approve', $document),
                'publish' => $user->can('publish', $document),
                'download' => $user->can('download', $document),
            ],
            'documentTypes' => Document::TYPES,
            'roles' => Role::orderBy('name')->get(['id', 'name']),
            'users' => User::tenantPicker()->get(['id', 'name']),
            'folderTree' => $this->documentService->folderTree(),
        ]);
    }

    public function update(DocumentRequest $request, Document $document)
    {
        $this->authorize('update', $document);

        $this->documentService->update(
            $document,
            collect($request->validated())->except(['file', 'change_summary'])->all(),
            $request->file('file'),
            $request->user(),
            $request->input('change_summary'),
        );

        return back()->with('success', 'Document updated.');
    }

    public function submit(Document $document)
    {
        $this->authorize('submit', $document);

        $this->documentService->submitForReview($document);

        return back()->with('success', 'Document submitted for approval.');
    }

    public function approve(Document $document)
    {
        $this->authorize('approve', $document);

        $this->documentService->approve($document, auth()->user());

        return back()->with('success', 'Document approved — it can now be published.');
    }

    public function reject(Request $request, Document $document)
    {
        $this->authorize('approve', $document);

        $validated = $request->validate(['reason' => ['required', 'string', 'min:5']]);

        $this->documentService->reject($document, auth()->user(), $validated['reason']);

        return back()->with('success', 'Document returned to draft.');
    }

    public function publish(Request $request, Document $document)
    {
        $this->authorize('publish', $document);

        $this->documentService->publish($document, auth()->user(), $request->only(['review_due_at']));

        return back()->with('success', 'Document published.');
    }

    public function download(Document $document)
    {
        $this->authorize('download', $document);

        return $this->documentService->download($document, auth()->user());
    }

    // ── Folders ──────────────────────────────────────────────────────

    public function storeFolder(Request $request)
    {
        $this->authorize('manageFolders', Document::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'exists:document_folders,id'],
            'description' => ['nullable', 'string'],
        ]);

        $this->documentService->createFolder($validated, $request->user());

        return back()->with('success', 'Folder created.');
    }
}
