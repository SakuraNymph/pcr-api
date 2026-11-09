<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuildLog extends Model
{
    public const ACTION_CREATED = 'guild.created';
    public const ACTION_UPDATED = 'guild.updated';
    public const ACTION_INVITATION_SENT = 'invitation.sent';
    public const ACTION_INVITATION_ACCEPTED = 'invitation.accepted';
    public const ACTION_INVITATION_REJECTED = 'invitation.rejected';
    public const ACTION_INVITATION_REVOKED = 'invitation.revoked';
    public const ACTION_MEMBER_JOINED = 'member.joined';
    public const ACTION_MEMBER_LEFT = 'member.left';
    public const ACTION_MEMBER_KICKED = 'member.kicked';
    public const ACTION_VICE_APPOINTED = 'member.vice_appointed';
    public const ACTION_VICE_REVOKED = 'member.vice_revoked';
    public const ACTION_OWNER_TRANSFERRED = 'owner.transferred';
    public const ACTION_DISSOLVED = 'guild.dissolved';
    public const ACTION_SCHEDULE_CLEARED = 'schedule.cleared';
    public const ACTION_SCHEDULE_COPIED = 'schedule.copied';
    public const ACTION_APPLICATION_SUBMITTED = 'application.submitted';
    public const ACTION_APPLICATION_APPROVED = 'application.approved';
    public const ACTION_APPLICATION_REJECTED = 'application.rejected';
    public const ACTION_APPLICATION_WITHDRAWN = 'application.withdrawn';

    public $timestamps = false;

    protected $fillable = [
        'guild_id',
        'actor_uid',
        'target_uid',
        'action',
        'context',
        'created_at',
    ];

    protected $casts = [
        'id' => 'integer',
        'guild_id' => 'integer',
        'actor_uid' => 'integer',
        'target_uid' => 'integer',
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function guild(): BelongsTo
    {
        return $this->belongsTo(Guild::class, 'guild_id');
    }
}
