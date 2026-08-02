<link rel="stylesheet" href="{{ block_asset('styles.css') }}">

@php
    $sp = json_decode($link->type_params ?? '{}', true);
    if (!is_array($sp)) { $sp = []; }
    $username = (string) ($sp['bmc_username'] ?? '');
    $label    = trim((string) ($sp['button_label'] ?? '')) ?: 'Buy me a coffee';
@endphp

@if($username !== '' && preg_match('/^[a-z0-9_-]{3,30}$/', $username))
    <div class="button-entrance bmc-block-wrapper" style="--delay: {{ $initial ?? 1 }}s" id="buymeacoffee-{{ $link->id }}">
        @if(!empty($link->title))
            <h3 class="bmc-heading">{{ $link->title }}</h3>
        @endif
        <a class="button button-coffee button-hover icon-hover bmc-button"
           href="https://www.buymeacoffee.com/{{ urlencode($username) }}"
           target="_blank"
           rel="noopener noreferrer"
           aria-label="{{ $label }} — opens Buy Me a Coffee in a new tab">
            <i class="icon hvr-icon bi bi-cup-hot-fill" aria-hidden="true"></i>
            <span class="bmc-label">{{ $label }}</span>
        </a>
    </div>
@endif
