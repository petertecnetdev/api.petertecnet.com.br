<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Forecast extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'app_id','user_id','slug','original_statement','statement','summary','category','topics','entities',
        'deadline_at','locked_at','status','visibility','author_probability','community_probability',
        'platform_probability','platform_confidence','resolution_criteria','resolution_source_requirements',
        'analysis','model_version','moderation_status','participant_count','comment_count','evidence_count',
        'follow_count','resolved_at',
    ];

    protected $casts = [
        'topics'=>'array','entities'=>'array','analysis'=>'array','resolution_source_requirements'=>'array',
        'deadline_at'=>'datetime','locked_at'=>'datetime','resolved_at'=>'datetime',
        'author_probability'=>'float','community_probability'=>'float','platform_probability'=>'float',
        'platform_confidence'=>'float',
    ];

    public function application() { return $this->belongsTo(Application::class, 'app_id'); }
    public function user() { return $this->belongsTo(User::class); }

    public function scopePublicVisible($query)
    {
        return $query->where('visibility', 'public')->where('moderation_status', 'approved');
    }

    public function canEstimate(): bool
    {
        return $this->status === 'open'
            && $this->deadline_at?->isFuture()
            && (! $this->locked_at || $this->locked_at->isFuture());
    }
}
