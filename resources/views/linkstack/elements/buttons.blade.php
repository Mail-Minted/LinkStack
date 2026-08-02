<?php use App\Models\UserData; ?>

        {{-- Block layout — unified width for every block type so the
             bio page renders as a clean vertical column regardless of
             which mix of block types the operator added. Width scales
             fluidly between mobile (90 vw) and desktop (480 px max).
             Iframe blocks get aspect-ratio handling so they scale
             proportionally instead of locking to fixed pixel heights.
             See UI-PASS-PLAN.md Pass 1, item 5 for context. --}}
        <style>
            :root {
                --block-max-width: clamp(280px, 90vw, 480px);
            }
            /* Every block wrapper LinkStack uses (standard buttons via
               .button-entrance, custom_html blocks via their per-type
               wrapper classes, text/heading via .fadein, the accordion
               wrapper for collapsed blocks) gets the same width
               envelope. */
            .button-entrance,
            .button-spacer,
            .block-accordion,
            .mm-text-block,
            .mm-heading-block,
            .youtube-block-wrapper,
            .twitch-block-wrapper,
            .spotify-block-wrapper,
            .bmc-block-wrapper,
            .stripe-payment-wrapper,
            .contact-form-wrapper,
            .newsletter-wrapper {
                width: 100%;
                max-width: var(--block-max-width);
                margin-left: auto;
                margin-right: auto;
            }
            /* LinkStack's stock .button class hardcodes width: 300px
               in brands.css — override so buttons fill the new
               container envelope. */
            .button-entrance .button,
            .button-entrance .button-custom,
            .button-entrance .button-custom_website {
                width: 100% !important;
                max-width: 100% !important;
                box-sizing: border-box;
            }
            /* IFrame blocks — fluid width with proportional height.
               YouTube and Twitch are both 16:9; Spotify uses fixed
               px heights by content type (set inline on each iframe)
               so it just needs width:100% to fill the container. */
            .yt-frame-wrap,
            .tw-frame-wrap {
                position: relative;
                width: 100%;
                aspect-ratio: 16 / 9;
            }
            .yt-frame-wrap .yt-frame,
            .tw-frame-wrap .tw-frame {
                position: absolute;
                inset: 0;
                width: 100%;
                height: 100%;
                border: 0;
            }
            .sp-frame {
                width: 100%;
                max-width: 100%;
                border: 0;
            }
            /* True-center block titles. Stock rendering (text-align:
               center + inline icon) and generated themes (flex
               centering + relative nudge) both center the icon+title
               PAIR, which reads as the title sitting ~10-14px right
               of the button's real center. Pull the icon out of the
               text flow and pin it to the button's left edge so the
               title centers on the button itself. Symmetric side
               padding keeps long titles clear of the icon without
               skewing the centering. */
            .button-entrance .button {
                position: relative;
                /* Own the layout: themes disagree on .button display
                   (flex vs block vs inline-block, and animation CSS
                   overrides some of them), so single-line titles were
                   not reliably centered vertically either. Flex
                   centers the title box both ways on every theme. */
                display: flex !important;
                align-items: center;
                justify-content: center;
                text-align: center;
                padding-left: 44px !important;
                padding-right: 44px !important;
            }
            .button-entrance .button .icon {
                position: absolute;
                left: 14px;
                top: 50%;
                transform: translateY(-50%);
                /* neutralize the inline-flow nudges stock CSS and
                   themes give .icon */
                right: auto;
                bottom: auto;
                margin: 0;
                padding: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                line-height: 1;
            }
            /* Custom links with no icon picked render an empty <i>
               (no fa-* class) — hide it rather than pinning a blank
               box to the edge. */
            .button-entrance .button i.icon:not([class*="fa-"]) {
                display: none;
            }
        </style>

        @php
        $initial = 1;
        // Only emit the accordion CSS when at least one link on this
        // page is configured to start collapsed. Keeps the public
        // page byte-for-byte unchanged when the feature is unused.
        $hasCollapsedBlock = false;
        foreach ($links as $__l) {
            if (!empty($__l->collapsed) && !empty($__l->custom_html)) { $hasCollapsedBlock = true; break; }
        }
        @endphp

        @if($hasCollapsedBlock)
        <style>
            /* Collapsible custom_html blocks. <details>/<summary> native
               element — no JS, browser auto-opens it when a URL fragment
               points inside (which makes contact_form's withFragment()
               redirect-after-error continue to work).

               The summary carries the .button classes, so the collapsed
               row wears the THEME's real button face (and follows
               page-wide and per-block appearance overrides) instead of
               a theme-agnostic gray bar — the row IS this block's
               button when collapsed, and now it looks like one. Our
               rules below add only the accordion mechanics: layout
               envelope, centering, the chevron, marker removal. */
            .block-accordion {
                margin: 0 auto;
                width: 100%;
                max-width: var(--block-max-width);
            }
            .block-accordion-summary {
                position: relative;
                /* own the layout — themes disagree on .button display
                   and width (some hardcode 300px) */
                display: flex !important;
                align-items: center;
                justify-content: center;
                text-align: center;
                width: 100% !important;
                max-width: 100% !important;
                box-sizing: border-box;
                /* symmetric side padding keeps long titles clear of
                   the chevron without skewing the centering */
                padding-left: 40px !important;
                padding-right: 40px !important;
                margin-bottom: 16px;
                cursor: pointer;
                list-style: none;
                user-select: none;
            }
            .block-accordion-summary::-webkit-details-marker { display: none; }
            .block-accordion-summary::after {
                content: '⌄';
                position: absolute;
                right: 18px;
                top: 50%;
                font-size: 1.1rem;
                line-height: 1;
                opacity: 0.6;
                transition: transform 0.2s ease;
                /* 45% pivot keeps the glyph's visual center as the
                   rotation point (it sits high in its em box). */
                transform-origin: center 45%;
                transform: translateY(-50%);
            }
            .block-accordion[open] .block-accordion-summary::after {
                transform: translateY(-50%) rotate(180deg);
            }
            /* Hide each custom block's own heading when it's inside an
               accordion — the summary text already shows the heading,
               so showing it again inside would duplicate. */
            .block-accordion .cf-heading,
            .block-accordion .ns-heading,
            .block-accordion .sp-heading {
                display: none !important;
            }
        </style>
        @endif

        @include('linkstack.modules.block-libraries', ['links' => $links])

        @foreach($links as $link)
        @if(isset($link->custom_html) && $link->custom_html)
            @if(isset($link->ignore_container) && $link->ignore_container)
            </div></div></div>
            @endif
                @php setBlockAssetContext($link->type); @endphp
                @if(!empty($link->collapsed))
                    <details class="block-accordion" id="block-{{ $link->id }}">
                        <summary class="block-accordion-summary button button-default">{{ $link->title ?: 'View more' }}</summary>
                        @include('blocks::' . $link->type . '.display', ['link' => $link, 'initial' => $initial++])
                    </details>
                @else
                    @include('blocks::' . $link->type . '.display', ['link' => $link, 'initial' => $initial++])
                @endif
            @if(isset($link->ignore_container) && $link->ignore_container)
            <div class="container"><div class="row"><div class="column">
            @endif
        @else
            @switch($link->name)
                @case('icon')
                    @break
                @case('vcard')
                    <div style="--delay: {{ $initial++ }}s" class="button-entrance"><a id="{{ $link->id }}" class="button button-default button-click button-hover icon-hover" rel="noopener noreferrer nofollow noindex" href="{{ route('vcard') . '/' . $link->id }}"><img alt="{{ $link->name }}" class="icon hvr-icon" src="@if(theme('use_custom_icons') == "true"){{ url('themes/' . $GLOBALS['themeName'] . '/extra/custom-icons')}}/vcard{{theme('custom_icon_extension')}} @else{{ asset('\/assets/linkstack/icons\/')}}vcard.svg @endif"></i>{{ $link->title }}</a></div>
                        @break
                @case('phone')
                <div style="--delay: {{ $initial++ }}s" class="button-entrance"><a id="{{ $link->id }}" class="button button-default button-click button-hover icon-hover" rel="noopener noreferrer nofollow noindex" href="{{ mm_safe_href($link->link) }}"><img alt="{{ $link->name }}" class="icon hvr-icon" src="@if(theme('use_custom_icons') == "true"){{ url('themes/' . $GLOBALS['themeName'] . '/extra/custom-icons')}}/phone{{theme('custom_icon_extension')}} @else{{ asset('\/assets/linkstack/icons\/')}}phone.svg @endif"></i>{{ $link->title }}</a></div>
                    @break
                @case('custom')
                  {{-- Mail Minted always honors per-block custom_css (saved
                       by the Appearance section in /studio/edit-link). The
                       upstream LinkStack render gated this behind a theme
                       flag (theme('allow_custom_buttons') == "false") which
                       both bundled themes have set to false — defeating the
                       feature. We drop the gate so customization works on
                       any theme. --}}
                  @if($link->custom_css === "" || $link->custom_css === "NULL" || empty($link->custom_css))
                   <div style="--delay: {{ $initial++ }}s" class="button-entrance"><a id="{{ $link->id }}" class="button button-custom button-click button-hover icon-hover" rel="noopener noreferrer nofollow noindex" href="{{ mm_safe_href($link->link) }}" @if((UserData::getData($userinfo->id, 'links-new-tab') != false))target="_blank"@endif ><i style="color: {{$link->custom_icon}}" class="icon hvr-icon {{$link->custom_icon}}"></i>{{ $link->title }}</a></div>
                      @break
                   @else
                   <div style="--delay: {{ $initial++ }}s" class="button-entrance"><a id="{{ $link->id }}" class="button button-custom button-click button-hover icon-hover" style="{{ $link->custom_css }}" rel="noopener noreferrer nofollow noindex" href="{{ mm_safe_href($link->link) }}" @if((UserData::getData($userinfo->id, 'links-new-tab') != false))target="_blank"@endif ><i class="icon hvr-icon {{$link->custom_icon}}"></i>{{ $link->title }}</a></div>
                      @break
                    @endif
                @case('custom_website')
                   @if($link->custom_css === "" || $link->custom_css === "NULL" || empty($link->custom_css))
                     <div style="--delay: {{ $initial++ }}s" class="button-entrance"><a id="{{ $link->id }}" class="button button-custom_website button-click button-hover icon-hover" rel="noopener noreferrer nofollow noindex" href="{{ mm_safe_href($link->link) }}" @if((UserData::getData($userinfo->id, 'links-new-tab') != false))target="_blank"@endif ><img alt="{{ $link->name }}" class="icon hvr-icon" src="@if(file_exists(base_path("assets/favicon/icons/").localIcon($link->id))){{url('assets/favicon/icons/'.localIcon($link->id))}}@else{{getFavIcon($link->id)}}@endif" data-fallback="{{asset('assets/linkstack/icons/website.svg')}}">{{ $link->title }}</a></div>
                       @break
                   @else
                    <div style="--delay: {{ $initial++ }}s" class="button-entrance"><a id="{{ $link->id }}" class="button button-custom_website button-click button-hover icon-hover" style="{{ $link->custom_css }}" rel="noopener noreferrer nofollow noindex" href="{{ mm_safe_href($link->link) }}" @if((UserData::getData($userinfo->id, 'links-new-tab') != false))target="_blank"@endif ><img alt="{{ $link->name }}" class="icon hvr-icon" src="@if(file_exists(base_path("assets/favicon/icons/").localIcon($link->id))){{url('assets/favicon/icons/'.localIcon($link->id))}}@else{{getFavIcon($link->id)}}@endif" data-fallback="{{asset('assets/linkstack/icons/website.svg')}}">{{ $link->title }}</a></div>
                     @break
                   @endif
                   @default
                {{-- Apply per-block custom_css to predefined-brand
                     buttons too (Instagram, Facebook, etc.) so the
                     Appearance section works for any block type, not
                     just Custom Link. The brand class still ships the
                     default look; inline style overrides it when set. --}}
                <div style="--delay: {{ $initial++ }}s" class="button-entrance"><a id="{{ $link->id }}" class="button button-{{ $link->name }} button-click button-hover icon-hover" @if(!empty($link->custom_css) && $link->custom_css !== 'NULL') style="{{ $link->custom_css }}" @endif rel="noopener noreferrer nofollow noindex" href="{{ mm_safe_href($link->link) }}" @if((UserData::getData($userinfo->id, 'links-new-tab') != false))target="_blank"@endif ><img alt="{{ $link->name }}" class="icon hvr-icon" src="@if(theme('use_custom_icons') == "true"){{ url('themes/' . $GLOBALS['themeName'] . '/extra/custom-icons')}}/{{str_replace('default ','',$link->name)}}{{theme('custom_icon_extension')}} @else{{ asset('\/assets/linkstack/icons\/') . str_replace('default ','',$link->name) }}.svg @endif">{{ $link->title }}</a></div>
            @endswitch
        @endif
    @endforeach

    <script nonce="{{ csp_nonce() }}">
        // Favicon fallback — replaces the inline onerror handlers so
        // website-block icons still fall back to the default glyph under
        // a strict script-src CSP. Capture phase because <img> error
        // events don't bubble; runs immediately so it catches errors
        // that fire before DOMContentLoaded.
        document.addEventListener('error', function (event) {
            var t = event.target;
            if (t && t.tagName === 'IMG' && t.dataset && t.dataset.fallback && t.getAttribute('src') !== t.dataset.fallback) {
                t.src = t.dataset.fallback;
                t.removeAttribute('data-fallback');
            }
        }, true);

        document.addEventListener('DOMContentLoaded', function () {
            function handleClickOrTouch(event) {
                if (event.target.classList.contains('button-click')) {
                    var id = event.target.id;
                    if (!sessionStorage.getItem('clicked-' + id)) {
                        var url = '{{ route("clickNumber") }}/' + id;
                        fetch(url, {
                            method: 'GET',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                        });
                        sessionStorage.setItem('clicked-' + id, 'true');
                    }
                }
            }
    
            document.addEventListener('mousedown', function (event) {
                if (event.button === 0 || event.button === 1) {
                    handleClickOrTouch(event);
                }
            });
    
            document.addEventListener('touchstart', handleClickOrTouch);
        });
    </script>