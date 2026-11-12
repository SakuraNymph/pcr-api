<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFeedbackImage extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'user_feedback_images';

    protected $fillable = [
        'message_id',
        'path',
        'original_name',
        'mime_type',
        'file_size',
    ];

    protected $hidden = [
        'path',
    ];

    protected $casts = [
        'message_id' => 'integer',
        'file_size' => 'integer',
        'created_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(UserFeedbackMessage::class, 'message_id');
    }
}
