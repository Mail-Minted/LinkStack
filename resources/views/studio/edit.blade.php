@extends('layouts.sidebar')

@section('content')

{{-- Shared front-end deps for the unified editor, loaded once:
     - Font Awesome (brand glyphs) for the Social + Blocks tabs. JS
       variant, not CSS, to avoid the 404-ing relative webfont paths
       (same reason social-icons.blade used it).
     - appearance.css for the Appearance tab's controls + the shared
       .appearance-layout grid that splits tab content from preview. --}}
@push('sidebar-stylesheets')
<script nonce="{{ csp_nonce() }}" defer src="{{ asset('assets/external-dependencies/fontawesome.js') }}" crossorigin="anonymous"></script>
<link rel="stylesheet" href="{{ asset('assets/css/appearance.css') }}">
@endpush

<style>
    /* ===== Unified studio editor — top-level tab chrome ===== */
    .mm-edit-tabs {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        border-bottom: 1px solid rgba(128, 128, 128, 0.2);
        margin-bottom: 18px;
        padding-bottom: 0;
    }
    .mm-edit-tab {
        appearance: none;
        background: transparent;
        border: none;
        border-bottom: 2px solid transparent;
        padding: 10px 18px;
        font-size: 0.98rem;
        font-weight: 600;
        color: inherit;
        opacity: 0.6;
        cursor: pointer;
        transition: opacity 0.12s ease, border-color 0.12s ease;
    }
    .mm-edit-tab:hover { opacity: 0.9; }
    .mm-edit-tab.active {
        opacity: 1;
        border-bottom-color: var(--bs-primary, #3b82f6);
    }
    .mm-edit-tab .bi { margin-right: 6px; }

    .mm-pane { display: none; }
    .mm-pane.active { display: block; }

    /* Tab content sits in the left grid cell; the live preview the
       shell includes fills the right cell. Reuses .appearance-layout
       (1fr 1fr, stacks <=992px) so the split matches the old pages. */
    .mm-edit-content { min-width: 0; }

    /* Status bar: auto-save indicator + version History dropdown */
    .mm-history-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
        padding: 10px 14px;
        margin-bottom: 16px;
        border: 1px solid rgba(128, 128, 128, 0.25);
        border-radius: 8px;
        background: rgba(128, 128, 128, 0.06);
        font-size: 0.92rem;
    }
    .mm-save-indicator { margin-left: 8px; opacity: 0.7; font-size: 0.85rem; font-style: italic; }
    .mm-save-indicator--error { color: var(--bs-danger, #dc3545); opacity: 1; font-style: normal; }

    .mm-history { position: relative; }
    .mm-history summary { list-style: none; cursor: pointer; }
    .mm-history summary::-webkit-details-marker { display: none; }
    .mm-history-menu {
        position: absolute;
        right: 0;
        top: calc(100% + 6px);
        z-index: 30;
        min-width: 260px;
        max-height: 320px;
        overflow-y: auto;
        padding: 6px;
        border: 1px solid rgba(128, 128, 128, 0.3);
        border-radius: 8px;
        background: var(--bs-body-bg, #fff);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
    }
    .mm-history-item {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 10px;
        width: 100%;
        padding: 7px 10px;
        border: none;
        border-radius: 6px;
        background: transparent;
        color: inherit;
        text-align: left;
        font-size: 0.9rem;
        cursor: pointer;
    }
    .mm-history-item:hover { background: rgba(128, 128, 128, 0.12); }
    .mm-history-cause { opacity: 0.6; font-size: 0.8rem; white-space: nowrap; }
    .mm-history-empty { padding: 10px; opacity: 0.7; font-size: 0.88rem; }
</style>

<div class="container-fluid content-inner mt-n5 py-0">
  <div class="row">
    <div class="col-12">
      <div class="card rounded">
        <div class="card-body">

          {{-- Status bar. Edits auto-save and are live on the public page
               immediately (instant-live model); History restores the page
               to an earlier version (a restore point is captured when an
               editing session starts, and before every restore). --}}
          <div class="mm-history-bar">
            <span>
              <i class="bi bi-check-circle"></i> Changes save automatically and go live right away.
              <span class="mm-save-indicator" id="mm-save-indicator" aria-live="polite"></span>
            </span>
            <details class="mm-history">
              <summary class="btn btn-outline-secondary btn-sm"><i class="bi bi-clock-history"></i> History</summary>
              <div class="mm-history-menu">
                @forelse($versions as $v)
                  <form action="{{ route('restoreVersion', $v->id) }}" method="post" class="mb-0">
                    @csrf
                    <button type="submit" class="mm-history-item"
                            data-confirm="Restore your page to this version? Your current page is saved to History first, so you can undo this.">
                      <span>{{ \Illuminate\Support\Carbon::parse($v->created_at)->diffForHumans() }}</span>
                      <span class="mm-history-cause">{{ ['published' => 'previously live', 'before-restore' => 'before a restore'][$v->cause] ?? '' }}</span>
                    </button>
                  </form>
                @empty
                  <div class="mm-history-empty">No versions yet &mdash; one is saved automatically when you start editing.</div>
                @endforelse
              </div>
            </details>
          </div>

          {{-- Shared flash + validation surface. Every tab's form
               redirects back here (old GET routes now point at this
               page), so a single alert block serves them all. --}}
          @if(session()->has('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
          @endif
          @if($errors->any())
            <div class="alert alert-danger">
              <strong>Couldn't save:</strong>
              <ul class="mb-0 mt-1">
                @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
              </ul>
            </div>
          @endif

          {{-- ===== Top-level tabs ===== --}}
          <nav class="mm-edit-tabs" role="tablist" aria-label="Page editor sections">
            <button type="button" class="mm-edit-tab" data-mm-tab="basics"     role="tab"><i class="bi bi-person-vcard"></i> Basics</button>
            <button type="button" class="mm-edit-tab" data-mm-tab="appearance" role="tab"><i class="bi bi-palette-fill"></i> Appearance</button>
            <button type="button" class="mm-edit-tab" data-mm-tab="social"     role="tab"><i class="bi bi-share-fill"></i> Social</button>
            <button type="button" class="mm-edit-tab" data-mm-tab="blocks"     role="tab"><i class="bi bi-link-45deg"></i> Blocks</button>
          </nav>

          {{-- ===== Grid: tab content | shared live preview ===== --}}
          <div class="appearance-layout">
            <div class="mm-edit-content">
              <div class="mm-pane" id="pane-basics"     role="tabpanel">@include('studio.partials.edit.basics')</div>
              {{-- One styling home (Linktree layout): the theme gallery sits at
                   the top of the Appearance pane, the fine-tuning controls below.
                   The old standalone Themes tab is gone; #themes deep-links are
                   aliased to this pane in the tab JS. --}}
              <div class="mm-pane" id="pane-appearance" role="tabpanel">
                @include('studio.partials.edit.themes')
                @include('studio.partials.edit.appearance')
              </div>
              <div class="mm-pane" id="pane-social"     role="tabpanel">@include('studio.partials.edit.social')</div>
              <div class="mm-pane" id="pane-blocks"     role="tabpanel">@include('studio.partials.edit.blocks')</div>
            </div>

            @include('studio.partials.live-preview', ['littleLinkName' => $user->littlelink_name ?? null])
          </div>

        </div>
      </div>
    </div>
  </div>
</div>

{{-- appearance.js drives the Appearance tab (photo cropper, bg upload,
     swatch state, reset). Loaded once at body end. --}}
@push('sidebar-scripts')
<script nonce="{{ csp_nonce() }}" src="{{ asset('assets/js/mm-image-resize.js') }}?v={{ filemtime(public_path('assets/js/mm-image-resize.js')) }}"></script>
<script nonce="{{ csp_nonce() }}" src="{{ asset('assets/js/appearance.js') }}?v={{ filemtime(public_path('assets/js/appearance.js')) }}"></script>
<script nonce="{{ csp_nonce() }}">
(function () {
    var VALID = ['basics', 'appearance', 'social', 'blocks'];
    var tabs  = Array.prototype.slice.call(document.querySelectorAll('.mm-edit-tab'));
    var panes = {};
    VALID.forEach(function (t) { panes[t] = document.getElementById('pane-' + t); });

    function activate(name, push) {
        // The theme gallery merged into the Appearance pane; old #themes
        // bookmarks, redirects, and in-page links land there.
        if (name === 'themes') name = 'appearance';
        if (VALID.indexOf(name) === -1) name = 'basics';
        tabs.forEach(function (btn) {
            var on = btn.getAttribute('data-mm-tab') === name;
            btn.classList.toggle('active', on);
            btn.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        VALID.forEach(function (t) {
            if (panes[t]) panes[t].classList.toggle('active', t === name);
        });
        if (push && location.hash.replace('#', '') !== name) {
            history.replaceState(null, '', '#' + name);
        }
    }

    // Tab button clicks.
    tabs.forEach(function (btn) {
        btn.addEventListener('click', function () {
            activate(btn.getAttribute('data-mm-tab'), true);
        });
    });

    // In-page cross-links (e.g. "Profile photo lives on the Appearance
    // tab") carry data-mm-tab and switch tabs instead of navigating.
    document.addEventListener('click', function (e) {
        var link = e.target.closest('a[data-mm-tab]');
        if (!link) return;
        e.preventDefault();
        activate(link.getAttribute('data-mm-tab'), true);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    // Respond to hash changes (back/forward, or a redirect landing on
    // /studio/edit#appearance from an old bookmarked URL).
    window.addEventListener('hashchange', function () {
        activate(location.hash.replace('#', ''), false);
    });

    // Initial tab from the URL hash, default Basics.
    activate(location.hash.replace('#', '') || 'basics', false);
})();
</script>
<script nonce="{{ csp_nonce() }}">
(function () {
    // ---- Auto-save wiring ----------------------------------------------
    // Tabs persist edits by calling window.mmAutoSaveForm(); saves land
    // on the live page immediately (instant-live model). No manual Save
    // step, no unsaved-changes warning.
    var indicator  = document.getElementById('mm-save-indicator');
    var indicatorTimer = null;

    window.mmSaveStatus = function (state) {
        if (!indicator) return;
        clearTimeout(indicatorTimer);
        indicator.classList.toggle('mm-save-indicator--error', state === 'error');
        if (state === 'saving') {
            indicator.textContent = 'Saving…';
        } else if (state === 'saved') {
            indicator.textContent = 'Saved';
            indicatorTimer = setTimeout(function () { indicator.textContent = ''; }, 1500);
        } else if (state === 'error') {
            indicator.textContent = "Couldn't save — check your connection";
        } else {
            indicator.textContent = '';
        }
    };

    // Debounced per-form auto-save. POSTs the form to its own action; the
    // server persists to the draft and (harmlessly) redirects, which we
    // ignore. One in-flight timer per form. `onSaved` (optional) runs
    // after a successful save — tabs use it to refresh the live preview
    // or re-render server-driven fragments (e.g. the Social chip row).
    var timers = new WeakMap();
    window.mmAutoSaveForm = function (form, delay, onSaved) {
        if (!form) return;
        clearTimeout(timers.get(form));
        timers.set(form, setTimeout(function () {
            window.mmSaveStatus('saving');
            fetch(form.getAttribute('action'), {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            }).then(function (r) {
                if (!r.ok) throw new Error('save failed ' + r.status);
                window.mmSaveStatus('saved');
                if (onSaved) onSaved();
            }).catch(function () {
                window.mmSaveStatus('error');
            });
        }, delay || 700));
    };
})();
</script>
@endpush

@endsection
