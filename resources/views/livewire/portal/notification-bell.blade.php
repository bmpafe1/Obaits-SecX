<div style="position:relative;" x-data="{ open:false }">
    <button @click="open = !open" style="background:none;border:none;cursor:pointer;position:relative;font-size:1.2rem;">
        &#128276;
        @if ($items->count())
            <span style="position:absolute;top:-4px;right:-6px;background:#c0392b;color:#fff;border-radius:50%;font-size:.65rem;padding:0 5px;">{{ $items->count() }}</span>
        @endif
    </button>
    <div x-show="open" @click.outside="open=false" x-cloak
        style="position:absolute;right:0;top:2rem;background:#fff;border:1px solid #eee;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.1);width:300px;z-index:50;">
        @forelse ($items as $n)
            <a href="{{ $n->link ?? '#' }}" wire:click="markRead('{{ $n->id }}')"
               style="display:block;padding:.7rem;border-bottom:1px solid #f4f4f4;text-decoration:none;">
                <div style="font-weight:600;color:{{ $n->level === 'critical' ? '#c0392b' : '#0B2545' }};font-size:.9rem;">{{ $n->title }}</div>
                @if ($n->body)<div style="color:#5A6B82;font-size:.82rem;">{{ $n->body }}</div>@endif
            </a>
        @empty
            <div style="padding:.9rem;color:#9aa;font-size:.9rem;">{{ __('No new notifications.') }}</div>
        @endforelse
    </div>
</div>
