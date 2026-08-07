{{--
    Social tab — ported from /studio/social-icons.blade.php.
    The brand-icon *content*: which icons appear, their URLs, and the
    drag-to-reorder chip row. Posts to editIcons / reorderSocialIcons
    unchanged. (How the icons *look* — color/size/spacing — lives on the
    Appearance tab's "Social icon style" sub-tab.)

    Alerts are dropped here; the unified shell renders success/errors
    once at the top of the page.
--}}

@php
    /**
     * Brand catalog — single source of truth for the Social tab.
     * Each entry is [name, label, prefix-or-null, placeholder].
     *
     * `prefix` is the visible URL prefix the input renders (also
     * what UserController::editIcons' normaliser uses to prepend the
     * base URL on save). Brands with non-standard URL shapes
     * (Mastodon, Bluesky, WhatsApp, Discord) have prefix null —
     * those stay as full-URL paste inputs.
     */
    $brands = [
        ['instagram',  'Instagram', 'instagram.com/',     'yourhandle'],
        ['x-twitter',  'X',         'x.com/',             'yourhandle'],
        ['facebook',   'Facebook',  'facebook.com/',      'your.profile'],
        ['github',     'GitHub',    'github.com/',        'yourhandle'],
        ['linkedin',   'LinkedIn',  'linkedin.com/in/',   'yourhandle'],
        ['tiktok',     'TikTok',    'tiktok.com/@',       'yourhandle'],
        ['youtube',    'YouTube',   'youtube.com/@',      'yourchannel'],
        ['threads',    'Threads',   'threads.net/@',      'yourhandle'],
        ['twitch',     'Twitch',    'twitch.tv/',         'yourhandle'],
        ['pinterest',  'Pinterest', 'pinterest.com/',     'yourhandle'],
        ['snapchat',   'Snapchat',  'snapchat.com/add/',  'yourhandle'],
        ['reddit',     'Reddit',    'reddit.com/user/',   'yourhandle'],
        ['telegram',   'Telegram',  't.me/',              'yourhandle'],
        ['behance',    'Behance',   'behance.net/',       'yourhandle'],
        ['dribbble',   'Dribble',   'dribbble.com/',      'yourhandle'],
        ['mastodon',   'Mastodon',  null, 'https://mastodon.social/@you'],
        ['bluesky',    'Bluesky',   null, 'https://bsky.app/profile/you.bsky.social'],
        ['whatsapp',   'WhatsApp',  null, 'https://wa.me/15551234567'],
        ['discord',    'Discord',   null, 'https://discord.gg/invitecode'],
    ];

    $brandByName = [];
    foreach ($brands as [$name, $label, $prefix, $placeholder]) {
        $brandByName[$name] = compact('label', 'prefix', 'placeholder');
    }
@endphp

<section class='text-gray-400'>
  <h3 class="mb-4 card-header"><i class="fa-solid fa-icons"></i> {{__('messages.Page Icons')}}</h3>
  <p class="text-muted small">
    Small brand icons rendered as a row near the top of your public page.
    For brands not listed below, use <a href="#" data-mm-tab="blocks">Blocks &rarr; Predefined Site</a>.
  </p>

  {{-- ============================================
       TOP — Drag-to-reorder chip row
       Mirrors how icons display on the public page:
       a horizontal flex-wrap row of brand glyphs.
       ============================================ --}}
  <h5 class="mt-4 mb-2"><i class="bi bi-arrows-move"></i> Order</h5>
  @if($configuredIcons->isEmpty())
    <p class="text-muted small">No icons yet. Add a URL below to create your first.</p>
  @else
    <p class="text-muted small mb-2">Drag any chip to reorder. Click the &times; to remove.</p>
    <div id="sortable-icons" class="icon-chip-row mb-4">
      @foreach($configuredIcons as $icon)
        @php
          $label = $brandByName[$icon->title]['label'] ?? ucfirst($icon->title);
        @endphp
        <div class="icon-chip"
             data-link-id="{{ $icon->id }}"
             title="{{ $label }}: {{ $icon->link }}">
          <i class="fa-brands fa-{{ $icon->title }} chip-glyph"></i>
          <x-post-action :action="route('deleteLink', $icon->id)"
             class="chip-remove"
             title="Remove"
             data-confirm="Remove this social icon?">&times;</x-post-action>
        </div>
      @endforeach
    </div>
  @endif

  {{-- ============================================
       BOTTOM — URL editor for every brand
       ============================================ --}}
  <h5 class="mt-4 mb-2"><i class="bi bi-pencil"></i> URLs</h5>
  <p class="text-muted small">Type a URL or handle for any brand. Empty fields stay hidden.</p>

  <form action="{{ route('editIcons') }}" enctype="multipart/form-data" method="post">
    @csrf
    <div class="form-group col-lg-8">
      @foreach($brands as [$name, $label, $prefix, $placeholder])
        @php
          $saved = DB::table('links')
              ->where('user_id', Auth::id())
              ->where('title', $name)
              ->where('button_id', 94)
              ->value('link');
          $display = $saved ?: '';
          if ($prefix && $display !== '') {
              $pattern = '#^https?://' . preg_quote($prefix, '#') . '#i';
              if (preg_match($pattern, $display)) {
                  $display = preg_replace($pattern, '', $display);
              }
          }
        @endphp
        <div class="mb-3">
          <label class="form-label">{{ $label }}</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fab fa-{{ $name }}"></i></span>
            @if($prefix)
              <span class="input-group-text text-muted small">{{ $prefix }}</span>
            @endif
            <input type="{{ $prefix ? 'text' : 'url' }}"
                   class="form-control"
                   name="{{ $name }}"
                   value="{{ $display }}"
                   placeholder="{{ $placeholder }}">
          </div>
        </div>
      @endforeach

      <button type="submit" class="mt-2 btn btn-primary">
        <i class="bi bi-save"></i> {{__('messages.Save links')}}
      </button>
    </div>
  </form>
</section>

<style>
    /* Compact chip row — mirrors the public-page horizontal icon
       layout. Each chip is just the brand glyph, draggable as a
       whole; a tiny × in the corner removes the link. */
    .icon-chip-row {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        padding: 10px 12px;
        background: rgba(128, 128, 128, 0.06);
        border: 1px dashed rgba(128, 128, 128, 0.25);
        border-radius: 10px;
        min-height: 64px;
    }
    /* Match the public bio page's icon scale (32px glyph with
       padding) so the editor preview looks like what visitors see. */
    .icon-chip {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 52px;
        height: 52px;
        background: rgba(128, 128, 128, 0.08);
        border: 1px solid rgba(128, 128, 128, 0.25);
        border-radius: 10px;
        cursor: grab;
        transition: transform 0.12s ease, box-shadow 0.12s ease;
        user-select: none;
    }
    .icon-chip:hover {
        background: rgba(128, 128, 128, 0.18);
        transform: translateY(-1px);
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
    }
    .icon-chip:active { cursor: grabbing; }
    .chip-glyph {
        font-size: 28px;
        line-height: 1;
    }
    .chip-remove {
        position: absolute;
        top: -6px;
        right: -6px;
        width: 18px;
        height: 18px;
        line-height: 16px;
        text-align: center;
        background: #dc3545;
        color: #fff !important;
        border-radius: 50%;
        font-size: 14px;
        font-weight: bold;
        text-decoration: none !important;
        opacity: 0;
        transition: opacity 0.12s ease;
    }
    .icon-chip:hover .chip-remove { opacity: 1; }
    .chip-remove:hover { background: #b02a37; }

    .sortable-ghost {
        opacity: 0.35;
        background: rgba(59, 130, 246, 0.2) !important;
    }
    .sortable-drag {
        opacity: 0.85 !important;
        cursor: grabbing !important;
    }
</style>

@push('sidebar-scripts')
<script nonce="{{ csp_nonce() }}">
    (function () {
        var list = document.getElementById('sortable-icons');
        if (!list) return;
        if (typeof Sortable === 'undefined') {
            console.warn('Social icons: SortableJS not loaded; drag-to-reorder disabled.');
            return;
        }

        var csrf = document.querySelector('meta[name="csrf-token"]')?.content
                || document.querySelector('input[name="_token"]')?.value;

        Sortable.create(list, {
            animation: 150,
            ghostClass: 'sortable-ghost',
            dragClass: 'sortable-drag',
            // Don't initiate drag when the user clicks the × button.
            filter: '.chip-remove',
            preventOnFilter: false,
            onEnd: function () {
                var orderedIds = Array.prototype.map.call(
                    list.querySelectorAll('[data-link-id]'),
                    function (el) { return Number(el.getAttribute('data-link-id')); }
                );
                fetch('{{ route("reorderSocialIcons") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ order: orderedIds }),
                }).then(function (r) {
                    if (!r.ok) console.warn('Social icons reorder save failed:', r.status);
                }).catch(function (e) {
                    console.warn('Social icons reorder request errored:', e);
                });
            },
        });
    })();
</script>
@endpush
