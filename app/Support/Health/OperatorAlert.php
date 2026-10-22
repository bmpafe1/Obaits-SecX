<?php
namespace App\Support\Health;
 
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
 
class OperatorAlert
{
    public static function send(string $subject, array $problems): void
    {
        $body = collect($problems)
            ->map(fn ($p) => strtoupper($p['level']) . ' — ' . $p['area'] . ': ' . $p['message'])
            ->implode("\n");
 
        // Always log it.
        Log::channel('single')->warning("[HEALTH] {$subject}\n{$body}");
 
        // Email the operator if an address is configured (config/services.php: 'operator.email').
        $to = config('services.operator.email');
        if ($to) {
            try {
                Mail::raw($body, fn ($m) => $m->to($to)->subject($subject));
            } catch (\Throwable $e) {
                Log::warning('Operator alert email failed: ' . $e->getMessage());
            }
        }
    }
}
