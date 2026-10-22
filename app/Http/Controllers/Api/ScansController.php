<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Scanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Real External API endpoints for scans. Reuses the EXACT same
 * Scanner service the portal's own Livewire component calls -- zero
 * duplicated logic, same real validation rules (a device/domain scan
 * target must genuinely belong to the authenticated tenant).
 */
class ScansController extends Controller
{
    public function store(Request $request, Scanner $scanner)
    {
        $tenantId = $request->attributes->get('api_tenant_id');

        $validated = $request->validate([
            'type' => 'required|in:domain,device,website',
            'target' => 'required|string',
            'deep_scan' => 'boolean',
        ]);

        if ($validated['type'] === 'domain') {
            // Same real ownership check as the portal's own runScan().
            $domain = DB::table('tenant_domains')
                ->where('tenant_id', $tenantId)
                ->where('domain', $validated['target'])
                ->where('verified', true)
                ->first();
            if (!$domain) {
                return response()->json(['error' => 'Unknown or unverified domain for this tenant.'], 422);
            }
            $scanId = $scanner->launch($tenantId, $validated['target'], 'api');
        } elseif ($validated['type'] === 'device') {
            // REAL BUG CAUGHT BEFORE SHIPPING: Scanner::launch() itself
            // does NOT validate tenant ownership of its target at all --
            // confirmed directly by reading its real implementation. The
            // portal's own runScan() only stays safe because its own
            // dropdown is pre-filtered to this tenant's real devices via
            // assetTargets(); an API caller has no such natural
            // constraint. Enforcing the SAME real check here that
            // assetTargets() uses (Fleet's own tenant-scoped label
            // mechanism), so a tenant genuinely cannot scan another
            // tenant's device by simply naming its hostname/IP.
            $secret = DB::table('tenant_artifacts')
                ->where('tenant_id', $tenantId)
                ->where('tool', 'fleet')
                ->where('artifact_type', 'enroll_secret')
                ->value('external_id');
            $ownedTargets = [];
            if ($secret) {
                try {
                    $hosts = app(\App\Clients\FleetClient::class)->hostsForTenant($secret);
                    foreach ($hosts as $h) {
                        if (!empty($h['hostname'])) $ownedTargets[] = $h['hostname'];
                        if (!empty($h['primary_ip'])) $ownedTargets[] = $h['primary_ip'];
                    }
                } catch (\Throwable $e) {
                    $ownedTargets = [];
                }
            }
            if (!in_array($validated['target'], $ownedTargets, true)) {
                return response()->json(['error' => 'This device does not belong to your tenant, or could not be verified.'], 422);
            }
            $scanId = $scanner->launch($tenantId, $validated['target'], 'api');
        } else {
            $scanId = $scanner->launchWeb($tenantId, $validated['target'], $validated['deep_scan'] ?? false, 'api');
        }

        return response()->json(['scan_id' => $scanId, 'status' => 'started'], 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = $request->attributes->get('api_tenant_id');

        // ISOLATION: only ever return a scan genuinely owned by the
        // authenticated tenant.
        $scan = DB::table('scans')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        if (!$scan) {
            return response()->json(['error' => 'Scan not found.'], 404);
        }

        return response()->json($scan);
    }

    public function index(Request $request)
    {
        $tenantId = $request->attributes->get('api_tenant_id');

        $scans = DB::table('scans')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => $scans]);
    }
}
