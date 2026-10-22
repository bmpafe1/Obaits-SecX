<div>
    <h1 style="color:#0B2545;">{{ __('Ask your devices') }}</h1>
    <p style="color:#5A6B82;">{{ __('Ask a question of all your enrolled computers at once and see their live answers. Questions only read information — they never change anything on a machine.') }}</p>
 
    {{-- Preset questions --}}
    <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin:1rem 0;">
        @foreach ($presets as $key => $p)
            <button wire:click="runPreset('{{ $key }}')" wire:loading.attr="disabled"
                style="background:#fff;border:1px solid #17A2B8;color:#0B2545;border-radius:20px;padding:.5rem 1rem;cursor:pointer;font-size:.9rem;">
                {{ __($p['label']) }}
            </button>
        @endforeach
    </div>
 
    {{-- Advanced custom question --}}
    <details style="margin:1rem 0;">
        <summary style="cursor:pointer;color:#5A6B82;font-size:.9rem;">{{ __('Advanced: ask your own question (read-only osquery SQL)') }}</summary>
        <div style="display:flex;gap:.5rem;margin-top:.6rem;">
            <input type="text" wire:model="sql" placeholder="SELECT name, version FROM os_version;"
                style="flex:1;padding:.6rem;border:1px solid #ddd;border-radius:6px;font-family:monospace;font-size:.85rem;">
            <button wire:click="runCustom" wire:loading.attr="disabled"
                style="background:#0B2545;color:#fff;border:none;border-radius:8px;padding:.6rem 1.2rem;cursor:pointer;">{{ __('Ask') }}</button>
        </div>
    </details>
 
    <div wire:loading style="color:#5A6B82;margin:1rem 0;">{{ __('Asking your devices… this can take a few seconds.') }}</div>
 
    @if ($error)
        <div style="background:#fdecea;border-left:4px solid #c0392b;padding:.8rem;border-radius:6px;color:#c0392b;margin:1rem 0;">{{ $error }}</div>
    @endif
 
    @if ($result !== null)
        <h3 style="color:#0B2545;margin-top:1.5rem;">{{ $ranLabel }}</h3>
        @if (empty($result))
            <p style="color:#5A6B82;">{{ __('No devices answered. They may be offline right now.') }}</p>
        @else
            @foreach ($result as $hostRow)
                <div style="background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin:.8rem 0;padding:1rem;">
                    <strong style="color:#0B2545;">{{ $hostRow['host'] }}</strong>
                    @if (empty($hostRow['columns']))
                        <p style="color:#9aa;font-size:.85rem;margin:.4rem 0 0;">{{ __('No result (the answer was empty).') }}</p>
                    @else
                        <table style="width:100%;border-collapse:collapse;font-size:.85rem;margin-top:.5rem;">
                            <thead><tr style="text-align:left;color:#5A6B82;">
                                @foreach (array_keys($hostRow['columns'][0]) as $col)
                                    <th style="padding:.3rem .6rem;">{{ $col }}</th>
                                @endforeach
                            </tr></thead>
                            <tbody>
                                @foreach ($hostRow['columns'] as $row)
                                    <tr style="border-top:1px solid #f2f2f2;">
                                        @foreach ($row as $val)
                                            <td style="padding:.3rem .6rem;font-family:monospace;">{{ $val }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            @endforeach
        @endif
    @endif
</div>
