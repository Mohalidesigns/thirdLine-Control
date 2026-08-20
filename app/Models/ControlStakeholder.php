<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRichText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stakeholder unit on a cross-functional control (CR2-A). The single
 * role=owner row mirrors controls.unit_id (R-D); co-owners and
 * contributors see the control in their unit's register and co-owners are
 * notified when it fails. Stakeholders ADD units — they never replace the
 * control's canonical single owner.
 */
class ControlStakeholder extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasRichText;

    public const ROLES = ['owner', 'co_owner', 'contributor', 'consulted'];

    /** Roles whose units see the control in their register, badged "Shared". */
    public const SHARED_ROLES = ['co_owner', 'contributor'];

    protected $fillable = [
        'tenant_id', 'control_id', 'organisation_unit_id', 'role',
        'user_id', 'notes', 'notes_rich',
    ];

    /** Editor.js-backed fields — see HasRichText. */
    protected array $richText = ['notes'];

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    public function organisationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class);
    }

    /** The named contact in the stakeholder unit, where one is set. */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
