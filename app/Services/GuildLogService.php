<?php

namespace App\Services;

use App\Models\GuildLog;

class GuildLogService
{
    public function write(
        int $guildId,
        int $actorUid,
        string $action,
        ?int $targetUid = null,
        array $context = []
    ): void {
        GuildLog::query()->create([
            'guild_id' => $guildId,
            'actor_uid' => $actorUid,
            'target_uid' => $targetUid,
            'action' => $action,
            'context' => $context === [] ? null : $context,
            'created_at' => now(),
        ]);
    }
}
