<?php
namespace App\Support\Helpdesk;
 
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
 
class Tickets
{
    // Per-tenant sequential number. Tenants see #1, #2 — not a UUID.
    private static function nextNumber(string $tenantId): int
    {
        return (int) DB::table('tickets')->where('tenant_id', $tenantId)->max('number') + 1;
    }
 
    public static function raise(string $tenantId, array $data, ?string $userId): string
    {
        $id = (string) Str::uuid();
        $category = $data['category'] ?? 'other';

        // Routing is OPTIONAL. If the tenant has set an owner for this category,
        // assign automatically. If not, the ticket stays unassigned and whoever
        // manages the team picks it up. A one-person shop never needs to configure this.
        $assignee = DB::table('ticket_routing')
            ->where('tenant_id', $tenantId)
            ->where('category', $category)
            ->value('assigned_to');

        DB::table('tickets')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'number' => self::nextNumber($tenantId),
            'subject' => $data['subject'],
            'body' => $data['body'],
            'category' => $category,
            'priority' => $data['priority'] ?? 'normal',
            'status' => 'open',
            'raised_by' => $userId,
            'assigned_to' => $assignee,          // null if no rule — that is fine
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
 
    public static function comment(string $tenantId, string $ticketId, string $body, ?string $authorId, bool $internal = false): void
    {
        // Verify the ticket belongs to this tenant BEFORE writing.
        $owns = DB::table('tickets')->where('id', $ticketId)->where('tenant_id', $tenantId)->exists();
        if (!$owns) abort(403);
 
        DB::table('ticket_comments')->insert([
            'id' => (string) Str::uuid(),
            'ticket_id' => $ticketId,
            'tenant_id' => $tenantId,
            'author_id' => $authorId,
            'body' => $body,
            'is_internal' => $internal,
            'created_at' => now(), 'updated_at' => now(),
        ]);
 
        DB::table('tickets')->where('id', $ticketId)->update(['updated_at' => now()]);
    }
 
    public static function setStatus(string $tenantId, string $ticketId, string $status): void
    {
        $u = ['status' => $status, 'updated_at' => now()];
        if ($status === 'resolved') $u['resolved_at'] = now();
        if ($status === 'closed')   $u['closed_at'] = now();
 
        DB::table('tickets')->where('id', $ticketId)
            ->where('tenant_id', $tenantId)              // ISOLATION
            ->update($u);
    }
}
