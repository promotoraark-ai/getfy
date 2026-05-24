<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberLessonPdfAnnotation extends Model
{
    protected $fillable = [
        'user_id',
        'member_lesson_id',
        'file_index',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'file_index' => 'integer',
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(MemberLesson::class, 'member_lesson_id');
    }
}
