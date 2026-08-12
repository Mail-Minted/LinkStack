{{--
    Live preview partial — drops a phone-shaped iframe of the user's
    public bio page into any studio screen. Self-contained: brings its
    own CSS, no external stylesheet dependency.

    Usage:
        @include('studio.partials.live-preview')
            -> defaults to the currently authenticated user
        @include('studio.partials.live-preview', ['littleLinkName' => $handle])
            -> renders the preview for a specific handle

    Originally extracted from /studio/appearance.blade.php so the
    "My Blocks" page and the future unified block-edit page can use the
    same polished preview surface instead of inventing their own.
    See UI-PASS-PLAN.md Pass 2 for context.
--}}

@php
    $handle = $littleLinkName ?? (Auth::user()->littlelink_name ?? '');
    $previewUrl = $handle !== '' ? url('/@' . $handle) : url('/');
@endphp

<aside class="appearance-preview">
    <div class="appearance-preview-header">
        <h6 class="mb-0"><i class="bi bi-phone"></i> Live preview</h6>
        <a href="{{ $previewUrl }}" target="_blank" class="small">Open in new tab &rarr;</a>
    </div>
    <div class="appearance-preview-frame">
        <iframe id="appearance-preview-iframe"
                src="{{ $previewUrl }}"
                title="Live preview of your public page"
                loading="lazy"></iframe>
    </div>
</aside>

{{-- CSS shipped with the partial so it works on any page that
     includes it, without needing assets/css/appearance.css linked
     in the layout. Rules mirror the styling on the appearance
     editor; tweaks made there should be reflected here too. --}}
@once
<style>
    .appearance-preview {
        position: sticky;
        top: 20px;
        align-self: start;
    }
    .appearance-preview-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 12px;
        background: rgba(128, 128, 128, 0.1);
        border-radius: 8px 8px 0 0;
        border: 1px solid rgba(128, 128, 128, 0.2);
        border-bottom: none;
    }
    .appearance-preview-frame {
        border: 1px solid rgba(128, 128, 128, 0.2);
        border-radius: 0 0 8px 8px;
        overflow: hidden;
        background: #fff;
        /* Approximate mobile viewport so the preview feels phone-ish. */
        aspect-ratio: 9 / 14;
        min-height: 500px;
    }
    .appearance-preview-frame iframe {
        width: 100%;
        height: 100%;
        border: 0;
        display: block;
    }
    @media (max-width: 992px) {
        .appearance-preview {
            position: static;
        }
        .appearance-preview-frame {
            aspect-ratio: unset;
            height: 70vh;
        }
    }
</style>
@endonce
