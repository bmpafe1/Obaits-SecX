<?php

namespace App\Jobs;

use App\Support\Compliance\FrameworkDocGenerator;
use App\Support\Llm\LlmContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class GenerateFrameworkDocs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries = 1;

    public function __construct(
        public string $tenantId,
        public string $frameworkId,
        public string $frameworkName,
        public string $actor,
    ) {}

    /**
     * The generator is resolved inside the method rather than injected into the
     * signature, because it takes an LlmClient as a constructor dependency and
     * that client must be built for THIS tenant.
     *
     * A queued job has no session, so the tenant cannot be inferred at resolution
     * time. Inferring it wrongly would mean one customer's API key paying for
     * another customer's documents, with their compliance content sent to a
     * provider they never chose — an isolation break, not merely a billing error.
     *
     * So the tenant is stated explicitly, and stated BEFORE the container builds
     * anything. Injecting the generator into handle() would resolve it before the
     * method body ran, making this too late.
     */
    public function handle(): void
    {
        LlmContext::set($this->tenantId);

        try {
            $gen = app(FrameworkDocGenerator::class);
            $result = $gen->generate($this->frameworkId, $this->frameworkName);

            \App\Support\Audit::log($this->tenantId, $this->actor,
                'compliance.docs.ai_generated', $result['error'] ? 'failure' : 'success', 'llm',
                ['framework' => $this->frameworkName,
                 'created' => $result['created'], 'skipped' => $result['skipped'],
                 'error' => $result['error']]);

            \App\Support\Notifications::raise($this->tenantId,
                $result['error']
                    ? __('Could not draft documents for :f', ['f' => $this->frameworkName])
                    : __(':n draft documents were prepared for :f', ['n' => $result['created'], 'f' => $this->frameworkName]),
                ['level' => $result['error'] ? 'warning' : 'info',
                 'body' => $result['error'] ?? __('They are AI-drafted starting points — review them on the Compliance page.'),
                 'link' => '/portal/compliance']);

        } finally {
            // Queue workers are long-lived and handle jobs for many tenants in
            // one process. Leaving this set would carry the wrong tenant into
            // whatever job runs next.
            LlmContext::clear();
        }
    }
}