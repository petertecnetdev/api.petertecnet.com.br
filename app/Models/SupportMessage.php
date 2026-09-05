<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupportMessage extends Model
{
    use HasFactory;

    protected $fillable = ['support_ticket_id','user_id','author_type','body','is_internal','metadata'];
    protected $casts = ['is_internal' => 'boolean', 'metadata' => 'array'];

    public function ticket() { return $this->belongsTo(SupportTicket::class, 'support_ticket_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
