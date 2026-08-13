<?php use App\Models\UserData; ?>
{{--
    Basics tab — ported verbatim from /studio/page.blade.php.
    Display name, page description (CKEditor), and the page-behavior
    toggles (verified checkmark [admin/vip], share button, open links
    in new tab). Posts to editPage / editProfilePicture exactly as the
    standalone page did — no controller change.

    The profile photo intentionally stays on the Appearance tab (its
    upload + shape controls live together there); this tab links across
    to it for anyone looking.
--}}

<style>
    .ck-editor__editable[role="textbox"] {
        /* editing area */
        min-height: 200px;
    }

    .ck-content .image {
        /* block images */
        max-width: 80%;
        margin: 20px auto;
    }
</style>
<style>
    @supports (-webkit-appearance: none) or (-moz-appearance: none) {
      input[type=checkbox],
      input[type=radio] {
      --active: var(--bs-primary);
      --active-inner: #fff;
      --focus: 2px var(--bs-primary);
      --border: #BBC1E1;
      --border-hover: var(--bs-primary);
      --background: #fff;
      --disabled: #F6F8FF;
      --disabled-inner: #E1E6F9;
      -webkit-appearance: none;
      -moz-appearance: none;
      height: 21px;
      outline: none;
      display: inline-block;
      vertical-align: top;
      position: relative;
      margin: 0;
      cursor: pointer;
      border: 1px solid var(--bc, var(--border));
      background: var(--b, var(--background));
      transition: background 0.3s, border-color 0.3s, box-shadow 0.2s;
      }
    input[type=checkbox]:after,
    input[type=radio]:after {
        content: "";
        display: block;
        left: 0;
        top: 0;
        position: absolute;
        transition: transform var(--d-t, 0.3s) var(--d-t-e, ease), opacity var(--d-o, 0.2s);
      }
      input[type=checkbox]:checked,
    input[type=radio]:checked {
        --b: var(--active);
        --bc: var(--active);
        --d-o: .3s;
        --d-t: .6s;
        --d-t-e: cubic-bezier(.2, .85, .32, 1.2);
      }
      input[type=checkbox]:disabled,
    input[type=radio]:disabled {
        --b: var(--disabled);
        cursor: not-allowed;
        opacity: 0.9;
      }
      input[type=checkbox]:disabled:checked,
    input[type=radio]:disabled:checked {
        --b: var(--disabled-inner);
        --bc: var(--border);
      }
      input[type=checkbox]:disabled + label,
    input[type=radio]:disabled + label {
        cursor: not-allowed;
      }
      input[type=checkbox]:hover:not(:checked):not(:disabled),
    input[type=radio]:hover:not(:checked):not(:disabled) {
        --bc: var(--border-hover);
      }
      input[type=checkbox]:focus,
    input[type=radio]:focus {
        box-shadow: 0 0 0 var(--focus);
      }
      input[type=checkbox]:not(.switch),
    input[type=radio]:not(.switch) {
        width: 21px;
      }
      input[type=checkbox]:not(.switch):after,
    input[type=radio]:not(.switch):after {
        opacity: var(--o, 0);
      }
      input[type=checkbox]:not(.switch):checked,
    input[type=radio]:not(.switch):checked {
        --o: 1;
      }
      input[type=checkbox] + label,
    input[type=radio] + label {
        font-size: 14px;
        line-height: 21px;
        display: inline-block;
        vertical-align: top;
        cursor: pointer;
        margin-left: 4px;
      }

      input[type=checkbox]:not(.switch) {
        border-radius: 7px;
      }
      input[type=checkbox]:not(.switch):after {
        width: 5px;
        height: 9px;
        border: 2px solid var(--active-inner);
        border-top: 0;
        border-left: 0;
        left: 7px;
        top: 4px;
        transform: rotate(var(--r, 20deg));
      }
      input[type=checkbox]:not(.switch):checked {
        --r: 43deg;
      }
      input[type=checkbox].switch {
        width: 38px;
        border-radius: 11px;
      }
      input[type=checkbox].switch:after {
        left: 2px;
        top: 2px;
        border-radius: 50%;
        width: 15px;
        height: 15px;
        background: var(--ab, var(--border));
        transform: translateX(var(--x, 0));
      }
      input[type=checkbox].switch:checked {
        --ab: var(--active-inner);
        --x: 17px;
      }
      input[type=checkbox].switch:disabled:not(:checked):after {
        opacity: 0.6;
      }

      input[type=radio] {
        border-radius: 50%;
      }
      input[type=radio]:after {
        width: 19px;
        height: 19px;
        border-radius: 50%;
        background: var(--active-inner);
        opacity: 0;
        transform: scale(var(--s, 0.7));
      }
      input[type=radio]:checked {
        --s: .5;
      }
    }
    .txt-label{
        color: white;
        padding-left: 5px;
        font-size: 200%;
        position: relative;
    }
    .toggle-btn{
        padding-left: 20px;
    }
    .ch2{
        padding-top: 60px;
    }
</style>

<section class='text-gray-400'>
<h3 class="mb-4 card-header"><i class="bi bi-file-earmark-break"> {{__('messages.My Profile')}}</i></h3>

<div>
<div></div>
@foreach($pages as $page)
<form action="{{ route('editPage') }}" enctype="multipart/form-data" method="post">
    @csrf
    @if($page->littlelink_name != '')
    <div class="form-group col-lg-8 mb-3">
      <small class="text-muted">Profile photo lives on the <a href="#" data-mm-tab="appearance">Appearance tab</a>.</small>
    </div>
    @endif

    <div class="form-group col-lg-8">
        {{-- Page URL (handle) is intentionally not user-editable here:
             it's tied to Mail Minted provisioning + the customer's
             shared URLs. Admin panel can still change it via
             /admin/edit-user/{id} for legitimate support cases. --}}
        <label style="margin-top:15px">{{__('messages.Display name')}}</label>
        <div class="input-group">
            <input type="text" class="form-control" name="name" value="{{ $page->name }}" required>
        </div>
    </div>

    <div class="form-group col-lg-8">
        {{-- Browser tab title: sits in the auto-saving basics form, so it
             persists like the display name. Blank falls back to the
             display name -- no platform branding either way. --}}
        @php
            // getData() returns the string "null" when the user has no data
            // blob yet, and null when the key is unset -- neither should
            // reach the input as literal text.
            $mmTabTitle = UserData::getData(Auth::id(), 'tab-title');
            $mmTabTitle = is_string($mmTabTitle) && $mmTabTitle !== 'null' ? $mmTabTitle : '';
        @endphp
        <label style="margin-top:15px">Browser tab title</label>
        <div class="input-group">
            <input type="text" class="form-control" name="tabTitle" maxlength="60"
                   value="{{ $mmTabTitle }}" placeholder="{{ $page->name }}">
        </div>
        <small class="text-muted">The text shown in the browser tab. Leave blank to use your display name.</small>
    </div>

    <div class="form-group col-lg-8">
        <label>{{__('messages.Page Description')}}</label>
        <textarea class="form-control" name="pageDescription" rows="2" maxlength="250" placeholder="A short tagline shown under your name">{{ strip_tags($page->littlelink_description ?? '') }}</textarea>
        <small id="pageDescription-counter" class="text-muted d-block mt-1">0 / 250 characters</small>
    </div>

    @if(auth()->user()->role == 'admin' || auth()->user()->role == 'vip')
        <div class="form-group col-lg-8">
        <h5 style="margin-top:50px"> {{__('messages.Show checkmark')}}</h5>
        <p class="text-muted">{{__('messages.disableverified')}}</p>
          <div class="mb-3 form-check form-switch">
            <input name="checkmark" class="switch toggle-btn" type="checkbox" id="checkmark" <?php if(UserData::getData(Auth::user()->id, 'checkmark') == true){echo 'checked';} ?> />
            <label class="form-check-label" for="checkmark">{{__('messages.Enable')}}</label>
          </div>
        <input type="hidden" name="_token" value="{{csrf_token()}}">
    @endif
    @endforeach

    <div class="form-group col-lg-8">
      <h5 style="margin-top:50px">{{__('messages.Show share button')}}</h5>
      <p class="text-muted">{{__('messages.disablesharebutton')}}</p>
        <div class="mb-3 form-check form-switch">
          <input name="sharebtn" class="switch toggle-btn" type="checkbox" id="sharebtn" <?php if(UserData::getData(Auth::user()->id, 'disable-sharebtn') != "true"){echo 'checked';} ?> />
          <label class="form-check-label" for="sharebtn">{{__('messages.Enable')}}</label>
        </div>

        <div class="form-group col-lg-8">
          <h5 style="margin-top:50px">{{__('messages.Open links in new tab')}}</h5>
          <p class="text-muted">{{__('messages.openlinksnewtab')}}</p>
            <div class="mb-3 form-check form-switch">
              <input name="tablinks" class="switch toggle-btn" type="checkbox" id="tablinks" <?php if(UserData::getData(Auth::user()->id, 'links-new-tab') != false){echo 'checked';} ?> />
              <label class="form-check-label" for="tablinks">{{__('messages.Enable')}}</label>
            </div>
</form>

{{-- Browser tab icon (favicon) — its own form: file upload with an
     immediate effect, so it can't ride the auto-saving basics form. --}}
<div class="form-group col-lg-8">
  <h5 style="margin-top:50px">Browser tab icon</h5>
  <p class="text-muted">The small icon (favicon) shown in the browser tab when someone views your page. Square images work best — PNG or ICO, up to 512KB. Changes apply immediately.</p>
  @php $userFavicon = findFavicon(Auth::id()); @endphp
  @if($userFavicon !== 'error.error')
    <div class="mb-3 d-flex align-items-center" style="gap:12px">
      <img src="{{ asset('assets/img/favicon-img/'.$userFavicon) }}" alt="Current browser tab icon" width="32" height="32" style="border-radius:6px">
      <x-post-action :action="route('removeFavicon')" class="btn btn-sm btn-outline-danger" data-confirm="Remove your custom favicon?">Remove</x-post-action>
    </div>
  @endif
  <form action="{{ route('editFavicon') }}" method="post" enctype="multipart/form-data" class="d-flex align-items-center" style="gap:12px">
    @csrf
    <input type="file" class="form-control" name="image" accept=".png,.ico,.jpg,.jpeg,.webp" required style="max-width:320px">
    <button type="submit" class="btn btn-primary">Upload</button>
  </form>
</div>

<script nonce="{{ csp_nonce() }}">
(function () {
    // Live preview (Phase 4): patch the shared preview iframe as the user
    // edits Basics — name and description — so changes show instantly,
    // before the debounced save lands. Same-origin, so we can reach into
    // it directly.
    // Look the iframe up fresh each call: this partial is parsed before the
    // live-preview partial (which holds the iframe) is included, so a capture
    // at parse time would be null. It also survives the iframe reloading.
    function doc() {
        try {
            var f = document.getElementById('appearance-preview-iframe');
            return f && f.contentDocument;
        } catch (e) { return null; }
    }

    // Name: update only the leading text node so the verified badge span survives.
    window.mmPreviewName = function (val) {
        var d = doc(); if (!d) return;
        var h1 = d.querySelector('h1.dynamic-contrast'); if (!h1) return;
        if (h1.firstChild && h1.firstChild.nodeType === 3) {
            h1.firstChild.textContent = val;
        } else {
            h1.insertBefore(d.createTextNode(val), h1.firstChild);
        }
    };
    // Description is a plain-text tagline — set text, never HTML, so it can't
    // inject markup and matches the escaped server render.
    window.mmPreviewDesc = function (text) {
        var d = doc(); if (!d) return;
        var p = d.querySelector('.description-parent p'); if (p) p.textContent = text;
    };

    // Auto-save: name + description persist to the draft as you type
    // (debounced), no manual Save. basicsForm is the surrounding form.
    var nameInput  = document.querySelector('input[name="name"]');
    var descArea   = document.querySelector('textarea[name="pageDescription"]');
    var basicsForm = (nameInput && nameInput.closest('form')) || (descArea && descArea.closest('form'));
    function autoSaveBasics() {
        if (window.mmAutoSaveForm && basicsForm) window.mmAutoSaveForm(basicsForm, 800);
    }

    if (nameInput) {
        nameInput.addEventListener('input', function () {
            window.mmPreviewName(this.value);
            autoSaveBasics();
        });
    }

    // Description: live preview + character counter, both driven by the plain
    // textarea's input event.
    var counter = document.getElementById('pageDescription-counter');
    var LIMIT = 250;
    function syncDesc() {
        if (window.mmPreviewDesc) window.mmPreviewDesc(descArea.value);
        if (counter) {
            var len = descArea.value.length;
            counter.textContent = len + ' / ' + LIMIT + ' characters';
            counter.className = 'd-block mt-1 ' + (len > LIMIT * 0.9 ? 'text-danger' : 'text-muted');
        }
    }
    if (descArea) {
        descArea.addEventListener('input', function () { syncDesc(); autoSaveBasics(); });
        syncDesc();  // initialise the counter from the saved value
    }

    // Toggles (share button, open-in-new-tab, verified checkmark) live in
    // the same form — auto-save them on change too. And a stray Enter or
    // submit shouldn't reload the page; auto-save already persists.
    if (basicsForm) {
        basicsForm.addEventListener('change', autoSaveBasics);
        basicsForm.addEventListener('submit', function (e) { e.preventDefault(); autoSaveBasics(); });
    }
})();
</script>
</div>
</section>
