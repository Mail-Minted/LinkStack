<?php use App\Models\Button;

// Check if the LinkCount cookie is set
if (isset($_COOKIE['LinkCount'])) {
  // Set the expiration time of the cookie to one hour in the past
  setcookie('LinkCount', '', time() - 3600);
}

if(!function_exists('strp')){function strp($urlStrp){return str_replace(array('http://', 'https://'), '', $urlStrp);}}
?>
{{-- Defines getFavIcon() / localIcon() used by the block rows below.
     The old /studio/links page included these at its top; they were
     dropped in the port, which broke the page with an undefined-
     function error. Both guard with function_exists, so re-including
     them elsewhere on the page is safe. --}}
@include('components.favicon')
@include('components.favicon-extension')
{{--
    Blocks tab — ported from /studio/links.blade.php.
    The blocks list (drag-to-reorder via #links-table-body, wired by the
    globally-loaded assets/js/main-dashboard.js) plus delete/edit/add.

    STEP 2 (this commit): Add / Edit still navigate to the existing
    /studio/add-link and /studio/edit-link/{id} pages as a working
    fallback. STEP 3 replaces those with an inline editor panel rendered
    right here (no redirect). The #mm-block-editor slot below is the
    placeholder that step 3 will populate.

    Dropped vs the standalone page: the outer .appearance-layout grid
    and the live-preview include — the unified shell owns both.
--}}

@php
    // Human-readable block-type names for the per-row badge. The yml
    // blocks carry their own titles (LinkType::get()); the legacy
    // built-in types return null there, so they get explicit labels.
    $mmTypeNames = [
        'link'      => 'Link',
        'vcard'     => 'Contact card',
        'email'     => 'E-mail',
        'telephone' => 'Phone',
        'heading'   => 'Heading',
        'spacer'    => 'Spacer',
        'text'      => 'Text',
    ];
    foreach (\App\Models\LinkType::get() as $mmLt) {
        if (!empty($mmLt['title'])) {
            $mmTypeNames[$mmLt['typename']] = $mmLt['title'];
        }
    }
@endphp

<style>
/* Chip cluster pinned to the row's top-right corner — out of the
   title's text flow so it reads as labels on the box, not part of the
   name. Holds the type chip plus (when the block carries styling of
   its own) the mint "Customized" state chip. */
#links-table-body > [data-id] { position: relative; }
.mm-block-badges {
    position: absolute;
    top: 8px;
    right: 10px;
    /* the cluster is a direct child of a Bootstrap .row, whose
       `.row > *` rule forces width:100% — shrink-wrap it instead */
    width: auto !important;
    display: flex;
    align-items: center;
    gap: 6px;
}
.mm-block-type-badge {
    font-size: 0.62rem;
    font-weight: 600;
    letter-spacing: 0.02em;
    white-space: nowrap;
    /* neutral + readable in light AND dark mode; deliberately not the
       mint state-chips (edited/Customized) — this is passive metadata */
    background: rgba(128, 128, 128, 0.18);
    color: inherit;
    opacity: 0.9;
}
.sortable-handle {
    margin-right: 25px;
    width: 25px;
    height: auto;
    transform: rotate(90deg);
    cursor: grab;
    cursor: -webkit-grabbing;
    fill: currentColor;
}
.mm-blocks-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 1rem;
}
.mm-blocks-header > .btn { flex-shrink: 0; }
.delete{position:relative; color:transparent; background-color:tomato; border-radius:5px; left:5px; padding:5px 12px; cursor: pointer;}
.delete:hover{color:transparent;background-color:#f13d1d;}
</style>

<section class='text-gray-400'>
    <h3 class="card-header mm-blocks-header">
        <span><i class="bi bi-link-45deg"></i> My Blocks</span>
        <a class="btn btn-primary" href="{{ url('/studio/add-link') }}" data-mm-add>Add new Block</a>
    </h3>

    {{-- Everything list-shaped lives in this region: after a block is
         saved in the panel below, the region's HTML is re-fetched and
         swapped in place (no page reload) — see the script at the bottom. --}}
    <div id="mm-blocks-list-region">
        <div style="overflow-y: none;">

            <div id="links-table-body" data-page="{{request('page', 1)}}" data-per-page="{{$pagePage ? $pagePage : 0}}">
                @if($links->total() == 0)
                      <div class="col-6 text-center">
                        <p class="mt-5">{{__('messages.No Link Added')}}</p>
                      </div>
                @else
                @foreach($links as $link)
                @php $button = Button::find($link->button_id); if(isset($button->name)){$buttonName = $button->name;}else{$buttonName = 0;} @endphp
                @php if($buttonName == "default email"){$buttonName = "email";} if($buttonName == "default email_alt"){$buttonName = "email_alt";} @endphp
                @if($button && $button->name !== 'icon')
                <div class='row h-100 pb-0 mb-2 border rounded hvr-glow w-100' data-id="{{$link->id}}">
                    @php
                        // Type badge label: mapped name, or — for brand/site
                        // buttons (predefined or legacy rows without a type)
                        // — the brand itself, which is the informative part.
                        $mmTypeLabel = $mmTypeNames[$link->type] ?? null;
                        if ($mmTypeLabel === null || $link->type === 'predefined') {
                            $mmTypeLabel = ucwords(str_replace(['default ', '_'], ['', ' '], (string) $buttonName)) ?: 'Link';
                        }

                        // "Customized" chip: styling is stored sparsely
                        // (Phase 5/6), so the mere presence of custom_css
                        // (button channel) or appearance_heading /
                        // appearance_color (color channels in type_params)
                        // means this block has diverged from the theme.
                        $mmRowTP = json_decode($link->type_params ?? '', true) ?: [];
                        $mmBlockCustomized = trim((string) $link->custom_css) !== ''
                            || trim((string) ($mmRowTP['appearance_heading'] ?? '')) !== ''
                            || trim((string) ($mmRowTP['appearance_color'] ?? '')) !== '';
                    @endphp
                    <span class="mm-block-badges">
                        @if($mmBlockCustomized)
                        <span class="badge bg-soft-primary mm-edited-badge" title="This block has its own styling on top of your theme — open Edit to change it or reset it to the theme">Customized</span>
                        @endif
                        <span class="badge mm-block-type-badge">{{ $mmTypeLabel }}</span>
                    </span>
                    <div class="d-flex ">

                        <div class='col-auto p-2 my-auto mr-2' title="{{ $link->link }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="sortable-handle" viewBox="0 0 16 16">
                                <path d="M1 4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V4zm5 0a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4zm5 0a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1h-2a1 1 0 0 1-1-1V4zM1 9a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V9zm5 0a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V9zm5 0a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1h-2a1 1 0 0 1-1-1V9z"/>
                              </svg>
                        </div>

                    <div class='col h-100'>

                        <div class='row h-100'>
                            <div class='col-12 p-2' style="max-width:300px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;" title="{{ $link->title }}">
                                <span class='h6'>
                                    @if($button->name == "custom_website")
                                    <span class="bg-soft-secondary" style="border: 1px solid #d0d4d7 !important;border-radius:5px;width:25px!important;height:25px!important;"><img style="margin-bottom:3px;margin-left:4px;margin-right:4px;max-width:15px;max-height:15px;" alt="button-icon" class="icon hvr-icon" src="@if(file_exists(base_path("assets/favicon/icons/").localIcon($link->id))){{url('assets/favicon/icons/'.localIcon($link->id))}}@else{{getFavIcon($link->id)}}@endif" data-fallback="{{asset('assets/linkstack/icons/website.svg')}}"></span>
                                    @elseif($button->name == "space")
                                    <span class="bg-soft-secondary" style="border: 1px solid #d0d4d7 !important;border-radius:5px;width:25px!important;height:25px!important;"><i style="margin-left:2.83px;margin-right:-1px;color:#fff;" class='bi bi-distribute-vertical'>&nbsp;</i></span>
                                    @elseif($button->name == "heading")
                                    <span class="bg-soft-secondary" style="border: 1px solid #d0d4d7 !important;border-radius:5px;width:25px!important;height:25px!important;"><i style="margin-left:2.83px;margin-right:-1px;color:#fff;" class='bi bi-card-heading'>&nbsp;</i></span>
                                    @elseif($button->name == "text")
                                    <span class="bg-soft-secondary" style="border: 1px solid #d0d4d7 !important;border-radius:5px;width:25px!important;height:25px!important;"><i style="margin-left:2.83px;margin-right:-1px;color:#fff;" class='bi bi-fonts'>&nbsp;</i></span>
                                    @elseif($link->custom_icon && $link->type && $link->type !== 'predefined')
                                    <span class="bg-soft-secondary" style="border: 1px solid #d0d4d7 !important;border-radius:5px;width:25px!important;height:25px!important;"><i style="width:20px;margin:1px;color:#fff;" class='fa {{$link->custom_icon}}'>&nbsp;</i></span>
                                    @else
                                    <span class="bg-soft-secondary" style="border: 1px solid #d0d4d7 !important;border-radius:5px;width:25px!important;height:25px!important;"><img style="max-width:15px !important;" alt="button-icon" height="15" class="m-1 " src="{{ asset('\/assets/linkstack/icons\/') . $buttonName }}.svg "></span>
                                    @endif

                                    @if($button->name == "space")
                                        Spacer ({{ (int) $link->title }} px)
                                    @else
                                        {{strip_tags($link->title,'')}}
                                    @endif
                                    </span>

                                @if(!empty($link->link) and $button->name != "vcard")
                                <br>
                                <a title='{{$link->link}}' href="{{ $link->link}}" target="_blank" class="d-none d-md-block ml-4 text-muted small">{{Str::limit($link->link, 75 )}}</a>
                                <a title='{{$link->link}}' href="{{ $link->link}}" target="_blank" class="d-md-none ml-4 text-muted small">{{Str::limit($link->link, 25 )}}</a>
                                @elseif(!empty($link->link) and $button->name == "vcard")
                                <br><a href="{{ url('vcard/'.$link->id) }}" target="_blank" class="ml-4 small">{{__('messages.Download')}}</a>
                                @endif

                            </div>

                            <div class='col' class="text-right">
                                {{Str::limit($link->params['text'] ?? null, 150)  }}

                                @if($link->typename == 'video')
                                    @php
                                        $embed = OEmbed::get($link->link);
                                        if ($embed && $embed->hasThumbnail()) {
                                            echo "<img style='max-height: 150px;' src='".$embed->thumbnailUrl()."' />";
                                        }
                                    @endphp
                                @endif
                            </div>

                            <div class='col-12 py-1 px-3 m-0 mt-2'>

                                @if(!empty($link->link))
                                <span><i class="bi bi-bar-chart-line"></i> {{ $link->click_number }} {{__('messages.Clicks')}}</span>
                                @endif

                                {{-- me-1 belongs on the floated wrapper, not the button: inside the form it
     made the float 4px wider than the sibling Edit control and wrapped the
     two onto separate lines. --}}
                                <x-post-action :action="route('deleteLink', $link->id)" form-style="float: right;" form-class="me-1" data-confirm="{{ __('messages.confirm_delete', ['title' => $link->title]) }}" class="btn btn-sm btn-icon btn-danger" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Delete" data-bs-placement="top" data-original-title="{{__('messages.Delete')}}">
                                    <span class="btn-inner">
                                       <svg class="icon-20" width="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor">
                                          <path d="M19.3248 9.46826C19.3248 9.46826 18.7818 16.2033 18.4668 19.0403C18.3168 20.3953 17.4798 21.1893 16.1088 21.2143C13.4998 21.2613 10.8878 21.2643 8.27979 21.2093C6.96079 21.1823 6.13779 20.3783 5.99079 19.0473C5.67379 16.1853 5.13379 9.46826 5.13379 9.46826" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                                          <path d="M20.708 6.23975H3.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                                          <path d="M17.4406 6.23973C16.6556 6.23973 15.9796 5.68473 15.8256 4.91573L15.5826 3.69973C15.4326 3.13873 14.9246 2.75073 14.3456 2.75073H10.1126C9.53358 2.75073 9.02558 3.13873 8.87558 3.69973L8.63258 4.91573C8.47858 5.68473 7.80258 6.23973 7.01758 6.23973" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                                       </svg>
                                    </span>
                                 </x-post-action>

                                    <a style="float: right;" href="{{ route('editLink', $link->id ) }}" data-mm-edit class="btn btn-sm me-1 btn-icon btn-warning" data-bs-toggle="tooltip" data-bs-placement="top" data-original-title="{{__('messages.Edit')}}" aria-label="Edit" data-bs-original-title="{{__('messages.Edit')}}">
                                       <span class="btn-inner">
                                          <svg class="icon-20" width="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                             <path d="M11.4925 2.78906H7.75349C4.67849 2.78906 2.75049 4.96606 2.75049 8.04806V16.3621C2.75049 19.4441 4.66949 21.6211 7.75349 21.6211H16.5775C19.6625 21.6211 21.5815 19.4441 21.5815 16.3621V12.3341" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                                             <path fill-rule="evenodd" clip-rule="evenodd" d="M8.82812 10.921L16.3011 3.44799C17.2321 2.51799 18.7411 2.51799 19.6721 3.44799L20.8891 4.66499C21.8201 5.59599 21.8201 7.10599 20.8891 8.03599L13.3801 15.545C12.9731 15.952 12.4211 16.181 11.8451 16.181H8.09912L8.19312 12.401C8.20712 11.845 8.43412 11.315 8.82812 10.921Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                                             <path d="M15.1655 4.60254L19.7315 9.16854" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                                          </svg>
                                       </span>
                                    </a>

                                @if(file_exists(base_path("assets/favicon/icons/").localIcon($link->id)))<x-post-action :action="route('clearIcon', $link->id)" form-style="float: right;" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Add" data-bs-placement="top" data-original-title="Clear icon cache" class="mm-post-bare float-right hvr-grow p-1 text-primary"><i style="-webkit-text-stroke:1px;padding-right:5px;" class="bi bi-arrow-repeat"></i></x-post-action>@endif

                            </div>
                        </div>
                    </div>
                </div>
                </div>
                @endif
                @endforeach
                @endif
            </div>

        </div>

        <ul class="pagination justify-content-center">
            {!! $links ?? ''->links() !!}
        </ul>

        @if(count($links) > 3)<a class="btn btn-primary" href="{{ url('/studio/add-link') }}" data-mm-add>Add new Block</a>@endif
    </div>

    {{-- Outside the swapped region so it runs exactly once. --}}
    <script nonce="{{ csp_nonce() }}" type="text/javascript">
        // Save endpoint for drag-to-reorder (main-dashboard.js).
        window.mmSortLinkUrl = "{{ route('sortLinks') }}";
    </script>

    {{-- Inline editor panel. Add / Edit open the existing block editor
         in an embedded iframe right here (no page jump). The editor runs
         in its native document context inside the frame, so every block
         type's own scripts/styles keep working untouched. --}}
    <div id="mm-block-editor" class="mm-block-editor" hidden>
        <div class="mm-block-editor-head">
            <h5 class="mb-0" id="mm-block-editor-title"><i class="bi bi-pencil-square"></i> Edit block</h5>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="mm-block-editor-close">
                <i class="bi bi-x-lg"></i> Close
            </button>
        </div>
        <iframe id="mm-block-frame" title="Block editor" src="about:blank"></iframe>
    </div>
</section>

<style>
    .mm-block-editor {
        margin-top: 18px;
        border: 1px solid rgba(128, 128, 128, 0.25);
        border-radius: 12px;
        overflow: hidden;
        background: rgba(128, 128, 128, 0.04);
    }
    .mm-block-editor-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 14px;
        border-bottom: 1px solid rgba(128, 128, 128, 0.2);
        background: rgba(128, 128, 128, 0.08);
    }
    #mm-block-frame {
        width: 100%;
        height: 75vh;
        min-height: 520px;
        border: 0;
        display: block;
        background: transparent;
    }
</style>

@push('sidebar-scripts')
<script nonce="{{ csp_nonce() }}">
(function () {
    var panel = document.getElementById('mm-block-editor');
    var frame = document.getElementById('mm-block-frame');
    var title = document.getElementById('mm-block-editor-title');
    var closeBtn = document.getElementById('mm-block-editor-close');
    if (!panel || !frame) return;

    var ADD_URL = @json(url('/studio/add-link'));
    var SAVE_PATH = '/studio/edit-link'; // route('addLink') — both add + edit post here
    var EDITOR_RE = /\/studio\/(add-link|edit-link)/;
    var opened = false;

    // ---- In-place refresh (no page reload) -----------------------------
    // The block editor saves via fetch (submit interception below), so
    // after a save we re-fetch this page's HTML and swap the fresh list
    // region in, then reload the live preview. The whole editor keeps its
    // state (active tab, scroll, other tabs' unsent input).
    function refreshBlocksList() {
        return fetch(window.location.pathname, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.text(); }).then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var fresh = doc.getElementById('mm-blocks-list-region');
            var cur = document.getElementById('mm-blocks-list-region');
            if (fresh && cur) {
                cur.innerHTML = fresh.innerHTML;
                // The swap replaced #links-table-body, so rebind drag-to-
                // reorder to the new element.
                if (window.mmInitBlockSortable) window.mmInitBlockSortable();
            }
        }).catch(function () { /* list refresh is cosmetic; the save itself already succeeded */ });
    }

    function reloadPreview() {
        var pf = document.getElementById('appearance-preview-iframe');
        if (pf) { try { pf.contentWindow.location.reload(); } catch (e) { pf.src += ''; } }
    }

    function openEditor(url, label) {
        title.innerHTML = '<i class="bi bi-pencil-square"></i> ' + label;
        panel.hidden = false;
        opened = true;
        // Cache-bust so re-opening the same block reloads fresh state.
        var sep = url.indexOf('?') === -1 ? '?' : '&';
        frame.src = url + sep + 'embed=1';
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function closeEditor() {
        panel.hidden = true;
        frame.src = 'about:blank';
        opened = false;
        // Discard any live block-preview edits by reloading the main preview
        // back to the saved page.
        reloadPreview();
    }

    // Intercept Add / Edit clicks inside the Blocks pane.
    document.addEventListener('click', function (e) {
        var add = e.target.closest('a[data-mm-add]');
        if (add) {
            e.preventDefault();
            openEditor(add.getAttribute('href'), 'Add block');
            return;
        }
        var edit = e.target.closest('a[data-mm-edit]');
        if (edit) {
            e.preventDefault();
            openEditor(edit.getAttribute('href'), 'Edit block');
            return;
        }
    });

    closeBtn.addEventListener('click', closeEditor);

    // ---- Fetch-based save ----------------------------------------------
    // The embedded editor's forms post to route('addLink'). Intercept the
    // submit inside the (same-origin) frame and send it as XHR instead;
    // saveLink answers JSON for XHR. On success the list + preview refresh
    // in place — the old behaviour reloaded the whole editor page.
    function showFrameErrors(fdoc, form, errors) {
        var box = fdoc.querySelector('.mm-fetch-errors');
        if (!box) {
            box = fdoc.createElement('div');
            box.className = 'alert alert-danger mm-fetch-errors';
            form.insertBefore(box, form.firstChild);
        }
        box.textContent = '';
        var strong = fdoc.createElement('strong');
        strong.textContent = "Couldn't save:";
        box.appendChild(strong);
        var ul = fdoc.createElement('ul');
        ul.className = 'mb-0 mt-1';
        var msgs = [];
        Object.keys(errors || {}).forEach(function (k) {
            (errors[k] || []).forEach(function (m) { msgs.push(m); });
        });
        if (!msgs.length) msgs.push('Please check the form and try again.');
        msgs.forEach(function (m) {
            var li = fdoc.createElement('li');
            li.textContent = m;
            ul.appendChild(li);
        });
        box.appendChild(ul);
        box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function onFrameSubmit(e) {
        var form = e.target;
        if (!form || !form.action) return;
        var path;
        try { path = new URL(form.action, frame.contentWindow.location.href).pathname; }
        catch (err) { return; }
        if (path.indexOf(SAVE_PATH) === -1) return; // not a block-save form
        e.preventDefault();

        var fd = new FormData(form);
        // Safety net: FormData omits the submit button's own name/value.
        // ('Save and add more' injects a hidden param input first, so it's
        // covered either way.)
        if (e.submitter && e.submitter.name) fd.append(e.submitter.name, e.submitter.value);
        var addMore = fd.get('param') === 'add_more';
        var fdoc = frame.contentDocument;

        if (window.mmSaveStatus) window.mmSaveStatus('saving');
        fetch(form.action, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (r) {
            if (r.status === 422) {
                return r.json().then(function (j) {
                    if (window.mmSaveStatus) window.mmSaveStatus('');
                    showFrameErrors(fdoc, form, j.errors);
                });
            }
            if (!r.ok) throw new Error('save failed ' + r.status);
            if (window.mmSaveStatus) window.mmSaveStatus('saved');
            refreshBlocksList();
            if (addMore) {
                // Stay in the panel for the next block.
                frame.src = ADD_URL + '?embed=1';
                reloadPreview();
            } else {
                closeEditor(); // also reloads the preview
            }
        }).catch(function () {
            if (window.mmSaveStatus) window.mmSaveStatus('error');
        });
    }

    frame.addEventListener('load', function () {
        if (!opened) return; // ignore the initial about:blank
        var path, fdoc;
        try {
            path = frame.contentWindow.location.pathname;
            fdoc = frame.contentDocument;
        } catch (err) {
            return; // cross-origin shouldn't happen (same app); bail safely
        }
        if (EDITOR_RE.test(path)) {
            // Editor page (fresh open, or 'save and add more' round-trip):
            // hook its form submits so saves go through fetch.
            if (fdoc) fdoc.addEventListener('submit', onFrameSubmit, true);
            return;
        }
        // The frame navigated off the editor path (e.g. an in-editor cancel
        // link). No parent reload — just close and freshen the list.
        closeEditor();
        refreshBlocksList();
    });
})();
</script>
@endpush
