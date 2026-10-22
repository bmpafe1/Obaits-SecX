<?php
namespace App\Console\Commands;
 
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
 
#[Signature('compliance:validate {--fix : mark valid references as verified}')]
#[Description('Verify every control reference against the live framework in CISO Assistant')]
class ValidateCompliance extends Command
{
    public function handle(): int
    {
        $client = app(\App\Clients\CisoClient::class);
        $frameworks = $client->frameworks();
 
        if (empty($frameworks)) {
            $this->error('No frameworks loaded in CISO Assistant. Import one first.');
            return 1;
        }
 
        $broken = 0;
 
        foreach ($frameworks as $fw) {
            $fwId = $fw['id'];
            $this->newLine();
            $this->info("Checking {$fw['name']}");
 
            // The REAL control references, from the live framework.
            $real = collect($client->requirements($fwId))->pluck('ref_id')->filter()->all();
            $this->line('  ' . count($real) . ' assessable controls in this framework.');
 
            // What WE claim.
            $ours = DB::table('document_control_map')->where('framework_id', $fwId)->get();
            if ($ours->isEmpty()) {
                $this->warn('  No documents mapped to this framework yet.');
                continue;
            }
 
            $ok = 0;
            foreach ($ours as $m) {
                if (in_array($m->control_ref, $real, true)) {
                    $ok++;
                    if ($this->option('fix')) {
                        DB::table('document_control_map')->where('id', $m->id)
                            ->update(['verified' => true, 'verified_at' => now()]);
                    }
                } else {
                    $broken++;
                    $this->error("  ✗ {$m->doc_key} → {$m->control_ref}  DOES NOT EXIST in this framework");
                    if ($this->option('fix')) {
                        DB::table('document_control_map')->where('id', $m->id)
                            ->update(['verified' => false, 'verified_at' => null]);
                    }
                }
            }
            $this->line("  ✓ {$ok} references verified.");
 
            // Reverse check: which controls have NO document at all?
            $mapped = $ours->pluck('control_ref')->unique();
            $uncovered = collect($real)->diff($mapped);
            $this->line('  ' . $uncovered->count() . ' of ' . count($real)
                . ' controls have no document in our set.');
            if ($this->getOutput()->isVerbose()) {
                $uncovered->each(fn ($c) => $this->line("    – {$c}"));
            }
        }
 
        $this->newLine();
        if ($broken > 0) {
            $this->error("{$broken} BROKEN reference(s). The platform must not show these to a customer.");
            return 1;      // non-zero: fails a CI pipeline
        }
 
        $this->info('All control references verified against the live frameworks.');
        return 0;
    }
}
