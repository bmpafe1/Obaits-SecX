<div x-data="{}" x-on:brian-think.window="$wire.think()">
    {{-- Floating button --}}
    <button wire:click="toggle"
        style="position:fixed;bottom:24px;right:24px;z-index:9998;background:#17A2B8;color:#fff;border:none;border-radius:50%;width:56px;height:56px;box-shadow:0 4px 12px rgba(0,0,0,.2);cursor:pointer;font-size:1.4rem;">
        {{ $open ? '×' : '💬' }}
    </button>

    @if ($open)
    <div style="position:fixed;bottom:92px;right:24px;z-index:9998;width:380px;max-width:92vw;height:560px;max-height:78vh;background:#fff;border-radius:16px;box-shadow:0 8px 30px rgba(0,0,0,.25);display:flex;flex-direction:column;overflow:hidden;">

        <div style="background:#0B2545;color:#fff;padding:.8rem 1rem;">
            <strong>{{ __('Brian') }}</strong>
            <div style="font-size:.8rem;opacity:.8;">{{ __('Your virtual security advisor') }}</div>
        </div>

        <div style="flex:1;overflow-y:auto;padding:1rem;background:#f8f9fb;">
            @if (empty($messages))
                <p style="color:#5A6B82;font-size:.9rem;">
                    {{ __('Hello, I am Brian. Ask me about your security, or how to do something here. I can also write reports and policies for you.') }}
                </p>
            @endif

            @foreach ($messages as $i => $m)
                <div style="margin-bottom:.7rem;text-align:{{ $m['role'] === 'user' ? 'right' : 'left' }};">
                    <span style="display:inline-block;padding:.5rem .8rem;border-radius:12px;max-width:85%;
                        background:{{ $m['role'] === 'user' ? '#17A2B8' : '#fff' }};
                        color:{{ $m['role'] === 'user' ? '#fff' : '#0B2545' }};
                        box-shadow:0 1px 3px rgba(0,0,0,.08);text-align:left;">@if ($m['role'] === 'user'){{ $m['content'] }}@else{!! \Illuminate\Support\Str::markdown($m['content'], ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}@endif</span>

                    {{-- Anything substantial Brian writes can become a Word document. --}}
                    @if ($m['role'] === 'assistant' && strlen($m['content']) > 400)
                        <div style="margin-top:.35rem;">
                            <button wire:click="downloadAsWord({{ $i }})" wire:loading.attr="disabled"
                                style="background:none;border:1px solid #17A2B8;color:#17A2B8;border-radius:6px;padding:.2rem .6rem;font-size:.75rem;cursor:pointer;">
                                {{ __('Download as Word') }}
                            </button>
                            <span wire:loading wire:target="downloadAsWord({{ $i }})" style="color:#5A6B82;font-size:.75rem;margin-left:.4rem;">
                                {{ __('Preparing…') }}
                            </span>
                        </div>
                    @endif
                </div>
            @endforeach

            @if ($downloadLink)
                <div style="background:#e6ffe6;padding:.6rem;border-radius:8px;font-size:.85rem;margin-bottom:.7rem;">
                    <a href="{{ $downloadLink }}" download style="color:#1e824c;font-weight:600;">
                        {{ __('Your document is ready — click to download') }}
                    </a>
                    <div style="color:#5A6B82;font-size:.75rem;margin-top:.2rem;">
                        {{ __('It carries your organisation name, logo and address. Saved under Compliance.') }}
                    </div>
                </div>
            @endif

            @if ($thinking)
                <div style="color:#5A6B82;font-size:.85rem;">{{ __('Brian is thinking…') }}</div>
            @endif
        </div>

        <div style="display:flex;gap:.4rem;padding:.7rem;border-top:1px solid #eee;">
            <input wire:model="draft" wire:keydown.enter="send"
                placeholder="{{ __('Ask Brian…') }}"
                style="flex:1;padding:.5rem;border:1px solid #ddd;border-radius:8px;">
            <button wire:click="send"
                style="background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.5rem .9rem;cursor:pointer;">
                {{ __('Send') }}
            </button>
        </div>
    </div>
    @endif
</div>
