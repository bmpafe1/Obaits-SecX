<?php
namespace App\Console\Commands;
 
use Illuminate\Console\Command;
use App\Support\Health\HealthMonitor;
 
class HealthCheck extends Command
{
    protected $signature = 'health:check {--no-act : report only, do not restart anything}';
    protected $description = 'Check platform health, restart safe services, and alert on problems.';
 
    public function handle(HealthMonitor $monitor): int
    {
        $report = $monitor->run(autoAct: !$this->option('no-act'));
 
        foreach ($report as $line) {
            $colour = match ($line['level']) {
                'critical' => 'error',
                'warning'  => 'comment',
                default    => 'info',
            };
            $this->{$colour}("[{$line['level']}] {$line['area']}: {$line['message']}");
        }
        return self::SUCCESS;
    }
}
