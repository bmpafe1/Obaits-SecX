<div>
    <h1 style="color:#0B2545;">{{ __('Protect a computer') }}</h1>
    <p style="color:#5A6B82;">{{ __('Install the SecX monitoring agent on each computer you want protected. It takes one command, run once per machine. The computer will appear below within a few minutes.') }}</p>
 
    @if (!$ready)
        <div style="background:#fff8e1;border-left:4px solid #b8860b;padding:1rem;border-radius:8px;">
            {{ __('Your monitoring is still being set up. Please check back shortly, or contact support if this persists.') }}
        </div>
    @else
        {{-- OS chooser --}}
        <div style="display:flex;gap:.3rem;margin:1rem 0;">
            @foreach (['linux'=>'Linux','macos'=>'macOS','windows'=>'Windows'] as $k => $label)
                <button wire:click="setOs('{{ $k }}')"
                    style="padding:.5rem 1.2rem;border:1px solid #17A2B8;background:{{ $os===$k ? '#17A2B8' : '#fff' }};color:{{ $os===$k ? '#fff' : '#0B2545' }};border-radius:6px;cursor:pointer;">
                    {{ $label }}
                </button>
            @endforeach
        </div>
 
        @if (count($tier1Options) > 1)
        <div style="display:flex;gap:.3rem;margin:0 0 1rem;">
            <span style="align-self:center;color:#5A6B82;font-size:.85rem;margin-right:.4rem;">{{ __('This machine is a:') }}</span>
            @foreach ($tier1Options as $key => $groupName)
                <button wire:click="setMachineType('{{ $key }}')"
                    style="padding:.4rem 1rem;border:1px solid #17A2B8;background:{{ $machineType===$key ? '#17A2B8' : '#fff' }};color:{{ $machineType===$key ? '#fff' : '#0B2545' }};border-radius:6px;cursor:pointer;font-size:.85rem;">
                    {{ ['server' => 'Server', 'desktop' => 'Desktop', 'workstation' => 'Workstation', 'dc' => 'Domain Controller'][$key] ?? ucfirst($key) }}
                </button>
            @endforeach
        </div>
        @endif

        <div style="background:#0B2545;border-radius:10px;padding:1.2rem;position:relative;">
            <div style="color:#8fa3bf;font-size:.78rem;margin-bottom:.5rem;">{{ __('Copy and run this on the computer:') }}</div>
            <pre id="enrollCmd" style="color:#CADCFC;font-size:.82rem;white-space:pre-wrap;word-break:break-all;margin:0;font-family:monospace;">{{ $command }}</pre>
            <button onclick="navigator.clipboard.writeText(document.getElementById('enrollCmd').innerText)"
                style="position:absolute;top:1rem;right:1rem;background:#17A2B8;color:#fff;border:none;border-radius:6px;padding:.3rem .8rem;cursor:pointer;font-size:.8rem;">
                {{ __('Copy') }}
            </button>
        </div>
 
        <div style="background:#fdf6e3;border-left:4px solid #b8860b;padding:.8rem;border-radius:6px;margin:.8rem 0;font-size:.85rem;color:#7a5c00;">
            {{ __('Keep this command private. It lets a computer join your organisation. Only run it on computers you own and want protected.') }}
        </div>
 
        <ol style="color:#5A6B82;font-size:.9rem;line-height:1.7;">
            <li>{{ __('Copy the command above.') }}</li>
            <li>{{ __('On the computer you want to protect, open a terminal (or PowerShell as Administrator on Windows).') }}</li>
            <li>{{ __('Paste and run it. It installs the monitoring agent and connects it to your organisation.') }}</li>
            <li>{{ __('Within a few minutes, the computer appears in the list below.') }}</li>
        </ol>
 
        {{-- Live confirmation --}}
        <h3 style="color:#0B2545;margin-top:2rem;">{{ __('Your protected computers') }} ({{ count($enrolled) }})</h3>
        <div wire:poll.15s>
            @if (empty($enrolled))
                <p style="color:#5A6B82;">{{ __('No computers are reporting yet. After you run the command on a machine, it will appear here shortly.') }}</p>
            @else
                <div style="background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:hidden;">
                    <table style="width:100%;border-collapse:collapse;font-size:.88rem;">
                        <thead><tr style="text-align:left;color:#5A6B82;background:#f8f9fb;">
                            <th style="padding:.6rem;">{{ __('Computer') }}</th>
                            <th style="padding:.6rem;">{{ __('Operating system') }}</th>
                            <th style="padding:.6rem;">{{ __('Status') }}</th>
                        </tr></thead>
                        <tbody>
                        @foreach ($enrolled as $h)
                            <tr style="border-top:1px solid #f2f2f2;">
                                <td style="padding:.6rem;">{{ $h['hostname'] ?? $h['display_name'] ?? '—' }}</td>
                                <td style="padding:.6rem;color:#5A6B82;">{{ $h['os_version'] ?? $h['platform'] ?? '—' }}</td>
                                <td style="padding:.6rem;"><span style="background:#eef7f0;color:#1e824c;border-radius:4px;padding:.1rem .5rem;font-size:.78rem;">{{ __('Protected') }}</span></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
</div>
