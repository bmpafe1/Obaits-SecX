<?php
namespace App\Console\Commands;
 
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use App\Support\Reconciliation\ArtifactReconciler;
 
#[Signature('artifacts:reconcile {--tenant= : limit to one tenant} {--heal : recreate safe artifacts that are missing}')]
#[Description('Verify every provisioned artifact still exists in its tool')]
class ReconcileArtifacts extends Command
{
    public function handle(): int
    {
        $s = ArtifactReconciler::run($this->option('tenant') ?: null);
 
        $this->info("Verified:    {$s['verified']}");
        if ($s['missing'] > 0)     $this->error("Missing:     {$s['missing']}  (drift — protection claimed but gone)");
        if ($s['unreachable'] > 0) $this->warn("Unreachable: {$s['unreachable']}  (tool did not respond)");
        if ($this->option('heal') && $s['missing'] > 0) {
            $this->newLine();
            $this->info('Attempting to repair safe artifacts…');

            $r = ArtifactReconciler::heal($this->option('tenant') ?: null);

            foreach ($r['healed'] as $h) {
                $this->info("  recreated: {$h}");
            }
            foreach ($r['skipped'] as $sk) {
                $this->warn("  NOT repaired (needs a human): {$sk}");
            }

            if ($r['skipped']) {
                $this->newLine();
                $this->warn('Some artifacts were left alone deliberately. Recreating them would issue '
                    . 'new credentials that people must redistribute, or would hide a deliberate '
                    . 'administrative action. Look at why they disappeared.');
            }

            return empty($r['skipped']) ? 0 : 1;
        }
        // Non-zero exit if anything drifted — so this can gate a health check.
        return $s['missing'] > 0 ? 1 : 0;


    }
}
