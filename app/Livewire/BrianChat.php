<?php
namespace App\Livewire;
 
use Livewire\Component;
use App\Support\Ai\BrianAssistant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
 
class BrianChat extends Component
{
    public bool $open = false;
    public string $draft = '';
    public array $messages = [];   // [ ['role'=>'user'|'assistant','content'=>...], ... ]
    public bool $thinking = false;
    public ?string $downloadLink = null;
 
    public function toggle() { $this->open = !$this->open; }
 
    public function send()
    {
        $text = trim($this->draft);
        if ($text === '') return;
 
        $tenantId = session('tenant_id');
        if (!$tenantId) { return; }
 
        $this->messages[] = ['role' => 'user', 'content' => $text];
        $this->draft = '';
        $this->thinking = true;
        // Ask the browser to trigger the actual model call on the next round-trip.
        $this->dispatch('brian-think');
    }
 
    public function think()
    {
        $tenantId = session('tenant_id');
        if (!$tenantId) { $this->thinking = false; return; }
 
        $locale = session('locale', app()->getLocale());
        $last = end($this->messages);
        $userMessage = $last ? $last['content'] : '';
 
        $reply = BrianAssistant::reply($tenantId, $userMessage, $this->messages, $locale);
 
        $this->messages[] = ['role' => 'assistant', 'content' => $reply];
        $this->thinking = false;
    }
 
    public function render()
    {
        return view('livewire.brian-chat');
    }

    public function downloadAsWord(int $index)
    {
        $tenantId = session('tenant_id');
        if (!$tenantId) return;

        $msg = $this->messages[$index] ?? null;
        if (!$msg || $msg['role'] !== 'assistant') return;

        $profile = \App\Support\TenantProfile::for($tenantId);

        // Title: the first heading Brian wrote, or a sensible default.
        preg_match('/^#+\s*(.+)$/m', $msg['content'], $m);
        $title = trim($m[1] ?? __('Report'));

        $file = \App\Support\Compliance\DocxRenderer::render(
            $tenantId,
            ['title' => $title],
            $profile,
            ['prepared_by' => [], 'reviewed_by' => [], 'approved_by' => []],
            $msg['content']
        );

        DB::table('tenant_documents')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $tenantId,
            'doc_key' => 'adhoc_' . \Illuminate\Support\Str::slug($title),
            'title' => $title,
            'mode' => 'automated',
            'status' => 'draft',
            'version' => '1.0',
            'body' => $msg['content'],
            'file_path' => $file,
            'header' => json_encode($profile),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->downloadLink = \Illuminate\Support\Facades\Storage::url($file);
    }
}
