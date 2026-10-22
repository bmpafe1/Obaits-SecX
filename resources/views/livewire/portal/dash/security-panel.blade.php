<div wire:init="load" style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
    <h3 style="margin-top:0;color:#0B2545;">{{ __('Security monitoring') }}
        <span style="color:#9aa;font-size:.8rem;font-weight:normal;">{{ __('last 24 hours') }}</span>
    </h3>
 
    @if (!$loaded)
        <p style="color:#9aa;">{{ __('Loading…') }}</p>
    @elseif ($error)
        <div style="background:#fff8e1;border-left:3px solid #b8860b;padding:.8rem;border-radius:6px;color:#5A6B82;">
            {{ $error }}
        </div>
    @else
        <div style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:center;">
            {{-- Severity numbers, Wazuh-style --}}
            <div style="display:flex;gap:1.5rem;flex:1;min-width:280px;">
                @php
                    $tiles = [
                        ['n'=>$data['severity']['critical'],'l'=>__('Critical'),'c'=>'#c0392b'],
                        ['n'=>$data['severity']['high'],'l'=>__('High'),'c'=>'#e67e22'],
                        ['n'=>$data['severity']['medium'],'l'=>__('Medium'),'c'=>'#17A2B8'],
                        ['n'=>$data['severity']['low'],'l'=>__('Low'),'c'=>'#1e824c'],
                    ];
                @endphp
                @foreach ($tiles as $t)
                    <div style="text-align:center;">
                        <div style="font-size:2rem;font-weight:bold;color:{{ $t['c'] }};">{{ $t['n'] }}</div>
                        <div style="color:#5A6B82;font-size:.82rem;">{{ $t['l'] }}</div>
                    </div>
                @endforeach
            </div>
 
            {{-- Agents donut (Chart.js), protected from Livewire re-render --}}
            <div wire:ignore style="width:160px;">
                <canvas id="agentsDonut"></canvas>
                <script>
                    (function(){
                        var el = document.getElementById('agentsDonut');
                        if (!el || !window.Chart) return;
                        if (el._chart) el._chart.destroy();
                        el._chart = new Chart(el, {
                            type: 'doughnut',
                            data: {
                                labels: ['{{ __("Active") }}','{{ __("Disconnected") }}'],
                                datasets: [{ data: [{{ $data['active'] }}, {{ $data['disconnected'] }}],
                                    backgroundColor: ['#1e824c','#c0392b'] }]
                            },
                            options: { plugins:{legend:{position:'bottom'}}, cutout:'65%' }
                        });
                    })();
                </script>
            </div>
        </div>
        @if ($data['total'] === 0)
            <p style="color:#9aa;font-size:.88rem;margin-top:1rem;">{{ __('No monitored devices yet.') }}</p>
        @endif
    @endif
</div>

