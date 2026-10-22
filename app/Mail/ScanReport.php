<?php
namespace App\Mail;
 
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;
 
class ScanReport extends Mailable
{
    use Queueable, SerializesModels;
 
    public function __construct(public string $scanId) {}
 
    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Your security scan results'));
    }
 
    public function content(): Content
    {
        $scan = DB::table('scans')->where('id', $this->scanId)->first();
        $tenant = DB::table('tenants')->where('id', $scan->tenant_id)->first();
        App::setLocale($tenant->preferred_language ?? 'en');
 
        $counts = DB::table('findings')->where('scan_id', $this->scanId)
            ->selectRaw("threat, count(*) as c")->groupBy('threat')->pluck('c', 'threat');
 
        $high = $counts['High'] ?? 0;
        $medium = $counts['Medium'] ?? 0;
        $low = $counts['Low'] ?? 0;
 
        $html = '<p>' . __('Hello') . ',</p>'
            . '<p>' . __('Your security scan of :target has completed.', ['target' => $scan->target]) . '</p>'
            . '<ul>'
            . '<li>' . __(':n high-risk issues', ['n' => $high]) . '</li>'
            . '<li>' . __(':n medium-risk issues', ['n' => $medium]) . '</li>'
            . '<li>' . __(':n low-risk issues', ['n' => $low]) . '</li>'
            . '</ul>'
            . '<p>' . __('Log in to your dashboard to see full details and recommended actions.') . '</p>'
            . '<p style="color:#888;font-size:12px;">' . __('Note: results reflect the systems reachable at scan time.') . '</p>';
 
        return new Content(htmlString: $html);
    }
}

