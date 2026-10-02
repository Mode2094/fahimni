<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'user_id', 'subject', 'category',
        'priority', 'channel', 'status', 'assigned_to', 'metadata', 'resolved_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class, 'ticket_id')->orderBy('created_at');
    }

    public static function attachLatestMessages($tickets): void
    {
        $ids = $tickets->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        $latest = TicketMessage::whereIn('ticket_id', $ids)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('ticket_id')
            ->map(fn ($group) => $group->first());

        foreach ($tickets as $ticket) {
            $ticket->setRelation('latestMessage', $latest->get($ticket->id));
        }
    }
}
