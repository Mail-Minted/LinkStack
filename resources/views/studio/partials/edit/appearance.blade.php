{{--
    Appearance tab — ported from /studio/appearance.blade.php.

    The full nested-pill machinery is lifted in intact: every input
    keeps its id and its form="appearance-form" attribute so the shared
    hidden save form (#appearance-form -> saveAppearance) still collects
    them, and assets/js/appearance.js still finds the same element ids.

    Two things were deliberately dropped vs the standalone page because
    the unified shell provides them once:
      - the outer .appearance-layout grid (shell wraps tab content +
        the single shared live preview)
      - the @include('studio.partials.live-preview') (shell owns it)
    The appearance.css / appearance.js tags also moved to the shell so
    they load once for the whole editor.

    The styling sub-tab is renamed "Social icon style" so it no longer
    collides with the top-level Social tab (which edits which icons
    appear, not how they look).
--}}

<section class="text-gray-400" style="margin-top: 2rem;">
  <h3 class="mb-4 card-header"><i class="bi bi-palette-fill"></i> Custom appearance</h3>
  <p class="text-muted">Fine-tune how your public page looks. Changes apply on top of your selected theme.</p>

  {{-- Wrapper: holds the tab nav + tab panes + the main save form.
       Inputs in tab panes other than "profile" use form="appearance-form"
       so they post with the main save even though they sit outside
       the form's DOM subtree. The profile tab contains its own
       independent multipart form for the photo upload. --}}
  <div class="appearance-form" id="appearance-wrap">

    <ul class="nav nav-pills appearance-tabs mb-3" role="tablist">
      <li class="nav-item" role="presentation"><button class="nav-link active" type="button" data-bs-toggle="pill" data-bs-target="#t-profile"    role="tab">Profile</button></li>
      <li class="nav-item" role="presentation"><button class="nav-link"        type="button" data-bs-toggle="pill" data-bs-target="#t-colors"     role="tab">Colors</button></li>
      <li class="nav-item" role="presentation"><button class="nav-link"        type="button" data-bs-toggle="pill" data-bs-target="#t-background" role="tab">Background</button></li>
      <li class="nav-item" role="presentation"><button class="nav-link"        type="button" data-bs-toggle="pill" data-bs-target="#t-type"       role="tab">Type</button></li>
      <li class="nav-item" role="presentation"><button class="nav-link"        type="button" data-bs-toggle="pill" data-bs-target="#t-buttons"    role="tab">Buttons</button></li>
      <li class="nav-item" role="presentation"><button class="nav-link"        type="button" data-bs-toggle="pill" data-bs-target="#t-social"     role="tab">Social icon style</button></li>
    </ul>

    <div class="tab-content">

      {{-- ===== Profile tab: own multipart form ===== --}}
      <div class="tab-pane fade show active" id="t-profile" role="tabpanel">
        <form class="appearance-section appearance-photo" action="{{ route('editProfilePicture') }}" method="post" enctype="multipart/form-data" id="appearance-photo-form">
          @csrf
          <legend>Profile photo</legend>
          <div class="appearance-photo-row">
            <div class="appearance-photo-stage" id="photo-stage" aria-label="Profile photo preview — drag the image to reposition" style="display:none">
              <img id="photo-stage-image" class="appearance-photo-stage-image" alt="Selected photo preview" draggable="false">
            </div>
            <div class="appearance-photo-controls">
              <div class="input-group">
                <input type="file" id="photo-file" name="image" class="form-control" accept="image/jpeg,image/jpg,image/png,image/webp" required>
                <button type="submit" class="btn btn-primary" id="photo-upload-btn">
                  <i class="bi bi-upload"></i> Upload
                </button>
              </div>

              <div id="photo-upload-status" class="small mt-2"></div>
              <div id="photo-edit-controls" class="mt-2" style="display:none">
                <label for="photo-zoom" class="small mb-1 d-block">
                  Zoom
                  <input type="range" id="photo-zoom" min="100" max="300" value="100" step="1" class="form-range">
                </label>
                <button type="button" id="photo-reset-btn" class="btn btn-sm btn-outline-secondary">
                  <i class="bi bi-arrow-counterclockwise"></i> Recenter
                </button>
              </div>

              <small class="text-muted d-block mt-2">
                Pick a photo, then drag inside the circle to reposition. JPG, PNG, or WebP &mdash; up to 2&nbsp;MB.
              </small>
              @if(file_exists(base_path(findAvatar(Auth::id()))))
                {{-- Submits the form declared after this one closes: the
                     delete is a POST now (CSRF), and a <form> cannot be
                     nested inside the photo-upload <form> above. Same
                     shape as appearance-reset-form further down. --}}
                <button type="submit" form="delete-photo-form"
                        class="mm-post-bare small text-danger mt-1 d-inline-block"
                        data-confirm="Delete your profile photo?">
                  <i class="bi bi-trash"></i> Remove current photo
                </button>
              @endif
            </div>
          </div>
        </form>

        @if(file_exists(base_path(findAvatar(Auth::id()))))
          {{-- Target of the "Remove current photo" button above. Lives out
               here because forms cannot nest. --}}
          <form id="delete-photo-form" action="{{ route('delProfilePicture') }}" method="post" style="display:none">
            @csrf
          </form>
        @endif

        {{-- Photo shape picker — same subject as the photo
             upload above, so it lives in this tab. Its
             inputs use form="appearance-form" so they post
             with the main save (not the photo upload). --}}
        <fieldset class="appearance-section">
          <legend>Photo shape @include('studio.partials.edited-badge', ['keys' => 'avatar.shape'])</legend>
          <div class="appearance-swatch-group" role="radiogroup">
            @foreach([['circle','Circle'],['rounded_square','Rounded square'],['off','Hidden']] as [$val, $lab])
              <label class="appearance-swatch" data-swatch="avatar-{{ $val }}">
                <input type="radio" name="avatar[shape]" value="{{ $val }}" form="appearance-form" @if($saved['avatar']['shape'] === $val) checked @endif>
                <span class="appearance-swatch-preview appearance-swatch-avatar appearance-swatch-avatar-{{ $val }}"></span>
                <span class="appearance-swatch-name">{{ $lab }}</span>
              </label>
            @endforeach
          </div>
        </fieldset>

        <fieldset class="appearance-section">
          <legend>Photo backdrop @include('studio.partials.edited-badge', ['keys' => 'avatar.backdrop'])</legend>
          @foreach([['theme', "Theme's disc", "A colored circle behind the photo — keeps logos visible on any background."], ['none', 'None — see-through', 'Transparent parts of your photo show the page background through them.']] as [$val, $lab, $hint])
            <div class="form-check">
              <input class="form-check-input" type="radio" name="avatar[backdrop]" id="avatar-backdrop-{{ $val }}" value="{{ $val }}" form="appearance-form" @if(($saved['avatar']['backdrop'] ?? 'theme') === $val) checked @endif>
              <label class="form-check-label" for="avatar-backdrop-{{ $val }}">
                <strong>{{ $lab }}</strong>
                <span class="small text-muted d-block">{{ $hint }}</span>
              </label>
            </div>
          @endforeach
        </fieldset>
      </div>

      {{-- ===== Colors tab ===== --}}
      <div class="tab-pane fade" id="t-colors" role="tabpanel">
        <fieldset class="appearance-section">
          <legend>Colors</legend>
          @include('studio.partials.appearance-color', ['id' => 'c-text', 'name' => 'colors[text]', 'label' => 'Text', 'help' => 'Primary text on the page', 'value' => $saved['colors']['text'], 'presets' => ['#111111', '#ffffff'], 'form' => 'appearance-form', 'editedKeys' => 'colors.text'])
        </fieldset>
      </div>

      {{-- ===== Background tab ===== --}}
      <div class="tab-pane fade" id="t-background" role="tabpanel">
        <fieldset class="appearance-section">
          <legend>Background</legend>

          <div class="mb-3">
            <label class="form-label d-block">Background type @include('studio.partials.edited-badge', ['keys' => 'background'])</label>
            @foreach([['solid','Solid color'],['gradient','Gradient'],['image','Image']] as [$val, $lab])
              <div class="form-check form-check-inline">
                <input class="form-check-input appearance-bg-type" type="radio" name="background[type]" id="bgt-{{ $val }}" value="{{ $val }}" form="appearance-form" @if($saved['background']['type'] === $val) checked @endif>
                <label class="form-check-label" for="bgt-{{ $val }}">{{ $lab }}</label>
              </div>
            @endforeach
          </div>

          <div class="appearance-bg-panel" data-bg-panel="solid" @if($saved['background']['type'] !== 'solid') style="display:none" @endif>
            @include('studio.partials.appearance-color', ['id' => 'bg-solid', 'name' => 'background[solid]', 'label' => 'Solid color', 'help' => null, 'value' => $saved['background']['solid'], 'form' => 'appearance-form'])
          </div>

          <div class="appearance-bg-panel" data-bg-panel="gradient" @if($saved['background']['type'] !== 'gradient') style="display:none" @endif>
            <div class="row g-2">
              <div class="col-md-6">
                @include('studio.partials.appearance-color', ['id' => 'bg-grad-start', 'name' => 'background[gradient_start]', 'label' => 'Gradient start', 'help' => null, 'value' => $saved['background']['gradient_start'], 'form' => 'appearance-form'])
              </div>
              <div class="col-md-6">
                @include('studio.partials.appearance-color', ['id' => 'bg-grad-end',   'name' => 'background[gradient_end]',   'label' => 'Gradient end',   'help' => null, 'value' => $saved['background']['gradient_end'], 'form' => 'appearance-form'])
              </div>
            </div>
            <div class="mt-2">
              <label class="form-label">Direction</label>
              <select class="form-select" name="background[gradient_direction]" form="appearance-form">
                @foreach([['to bottom','Top to bottom'],['to right','Left to right'],['to bottom right','Diagonal']] as [$val, $lab])
                  <option value="{{ $val }}" @if($saved['background']['gradient_direction'] === $val) selected @endif>{{ $lab }}</option>
                @endforeach
              </select>
            </div>
          </div>

          <div class="appearance-bg-panel" data-bg-panel="image" @if($saved['background']['type'] !== 'image') style="display:none" @endif>
            <input type="hidden" name="background[image_url]" id="bg-image-url-hidden" value="{{ $saved['background']['image_url'] }}" form="appearance-form">

            @if(!empty($saved['background']['image_url']))
              @php
                // No background override in the sparse blob means the
                // image shown is the THEME's own photo — nothing to
                // remove; uploading replaces it as an override.
                $mmBgIsThemeOwned = data_get($sparseAppearance ?? [], 'background') === null;
              @endphp
              <div class="appearance-bg-current mb-2" id="bg-current-wrap">
                <img src="{{ $saved['background']['image_url'] }}" alt="Current background" class="appearance-bg-thumb">
                @if($mmBgIsThemeOwned)
                  <span class="small text-muted d-inline-flex align-items-center" style="gap: 4px;">
                    <i class="bi bi-brush"></i> Your theme's background
                  </span>
                @else
                  <button type="button" id="bg-remove-btn" class="btn btn-sm btn-outline-danger" data-confirm="Remove the current background image?">
                    <i class="bi bi-trash"></i> Remove &mdash; back to theme
                  </button>
                @endif
              </div>
            @endif

            <label for="bg-file" class="form-label">Upload background image</label>
            <div class="input-group">
              <input type="file" id="bg-file" class="form-control" accept="image/jpeg,image/jpg,image/png,image/webp">
              <button type="button" id="bg-upload-btn" class="btn btn-primary">
                <i class="bi bi-upload"></i> Upload
              </button>
            </div>
            <small class="text-muted d-block mt-1">Auto-resized to 1920&nbsp;px max in your browser before upload. JPG, PNG, or WebP.</small>
            <div id="bg-upload-status" class="small mt-2"></div>
          </div>
        </fieldset>
      </div>

      {{-- ===== Typography tab ===== --}}
      <div class="tab-pane fade" id="t-type" role="tabpanel">
        <fieldset class="appearance-section">
          <legend>Typography</legend>
          <label for="font-select" class="form-label">Font @include('studio.partials.edited-badge', ['keys' => 'typography.font'])</label>
          <select id="font-select" class="form-select" name="typography[font]" form="appearance-form">
            <option value="" @if(empty($saved['typography']['font'])) selected @endif>Theme default</option>
            @foreach($fonts as $f)
              <option value="{{ $f }}" style="font-family: '{{ $f }}', sans-serif" @if($saved['typography']['font'] === $f) selected @endif>{{ $f }}</option>
            @endforeach
          </select>
          <small class="text-muted">Only the selected font is loaded from Google Fonts.</small>
        </fieldset>
      </div>

      {{-- ===== Buttons tab ===== --}}
      <div class="tab-pane fade" id="t-buttons" role="tabpanel">
        <fieldset class="appearance-section">
          <legend>Buttons</legend>

          <label class="form-label d-block">Shape @include('studio.partials.edited-badge', ['keys' => 'buttons.shape'])</label>
          <div class="appearance-swatch-group" role="radiogroup">
            @foreach(['pill','rounded','square'] as $shape)
              <label class="appearance-swatch" data-swatch="shape-{{ $shape }}">
                <input type="radio" name="buttons[shape]" value="{{ $shape }}" form="appearance-form" @if($saved['buttons']['shape'] === $shape) checked @endif>
                <span class="appearance-swatch-preview appearance-swatch-btn appearance-swatch-btn-{{ $shape }}">Button</span>
                <span class="appearance-swatch-name">{{ ucfirst($shape) }}</span>
              </label>
            @endforeach
          </div>

          <label class="form-label d-block mt-3">Style @include('studio.partials.edited-badge', ['keys' => 'buttons.style'])</label>
          <div class="appearance-swatch-group" role="radiogroup">
            @foreach(['filled','outline','soft'] as $style)
              <label class="appearance-swatch" data-swatch="style-{{ $style }}">
                <input type="radio" name="buttons[style]" value="{{ $style }}" form="appearance-form" @if($saved['buttons']['style'] === $style) checked @endif>
                <span class="appearance-swatch-preview appearance-swatch-btn-style-{{ $style }}">Button</span>
                <span class="appearance-swatch-name">{{ ucfirst($style) }}</span>
              </label>
            @endforeach
          </div>

          <style>
            /* Button text color applies only to filled buttons; dim the
               control + reveal its note when it can't do anything. */
            #c-btn-text-wrap.is-inactive { opacity: 0.45; }
            #c-btn-text-wrap.is-inactive input,
            #c-btn-text-wrap.is-inactive button,
            #c-btn-text-wrap.is-inactive .form-control { pointer-events: none; }
            #c-btn-text-wrap:not(.is-inactive) #c-btn-text-note { display: none; }
          </style>
          <div class="mt-3">
            @include('studio.partials.appearance-color', ['id' => 'c-primary',  'name' => 'colors[primary]',     'label' => 'Button color',      'help' => 'Fill (filled style) or border/text (outline, soft)', 'value' => $saved['colors']['primary'], 'form' => 'appearance-form', 'editedKeys' => 'colors.primary'])
            {{-- Button text color only affects FILLED buttons; outline/soft
                 draw their text in the button color (see AppearanceCss).
                 Initial dim state is server-set (no flash); appearance.js
                 keeps it in sync as the style radio changes. --}}
            <div id="c-btn-text-wrap" @if($saved['buttons']['style'] !== 'filled') class="is-inactive" @endif>
              @include('studio.partials.appearance-color', ['id' => 'c-btn-text', 'name' => 'colors[button_text]', 'label' => 'Button text color', 'help' => 'Text on filled buttons', 'value' => $saved['colors']['button_text'], 'form' => 'appearance-form', 'editedKeys' => 'colors.button_text'])
              <p id="c-btn-text-note" class="text-muted small mt-1 mb-0">
                Only affects <strong>filled</strong> buttons &mdash; outline &amp; soft buttons use the button color for their text.
              </p>
            </div>
          </div>
        </fieldset>
      </div>

      {{-- ===== Social icon style tab ===== --}}
      <div class="tab-pane fade" id="t-social" role="tabpanel">
        <fieldset class="appearance-section">
          <legend>Social icon style</legend>
          <p class="text-muted small mb-3">
            Controls how the brand-glyph row near the top of your bio page renders.
            Edit which icons appear on the <a href="#" data-mm-tab="social">Social tab</a>.
          </p>

          {{-- Color mode --}}
          <label class="form-label d-block">Color @include('studio.partials.edited-badge', ['keys' => 'social_icons.color social_icons.color_custom'])</label>
          <div class="appearance-swatch-group mb-2" role="radiogroup">
            @foreach([
              'auto'   => ['name' => 'Auto contrast', 'help' => 'Black on light bg, white on dark'],
              'brand'  => ['name' => 'Brand colors',  'help' => 'Each icon in its real brand color'],
              'custom' => ['name' => 'Custom color',  'help' => 'All icons the same color'],
            ] as $val => $meta)
              <label class="appearance-swatch" data-swatch="social-color-{{ $val }}" title="{{ $meta['help'] }}">
                <input type="radio" name="social_icons[color]" value="{{ $val }}" form="appearance-form" @if($saved['social_icons']['color'] === $val) checked @endif>
                <span class="appearance-swatch-name">{{ $meta['name'] }}</span>
              </label>
            @endforeach
          </div>
          <div class="mt-2" id="social-custom-color-wrap" @if($saved['social_icons']['color'] !== 'custom') style="display:none" @endif>
            @include('studio.partials.appearance-color', ['id' => 'social-color-custom', 'name' => 'social_icons[color_custom]', 'label' => 'Custom color', 'help' => null, 'value' => $saved['social_icons']['color_custom'], 'form' => 'appearance-form'])
          </div>

          {{-- Size --}}
          <label class="form-label d-block mt-3">Size @include('studio.partials.edited-badge', ['keys' => 'social_icons.size'])</label>
          <div class="appearance-swatch-group" role="radiogroup">
            @foreach(['small','medium','large','xl'] as $size)
              <label class="appearance-swatch" data-swatch="social-size-{{ $size }}">
                <input type="radio" name="social_icons[size]" value="{{ $size }}" form="appearance-form" @if($saved['social_icons']['size'] === $size) checked @endif>
                <span class="appearance-swatch-name">{{ $size === 'xl' ? 'Extra large' : ucfirst($size) }}</span>
              </label>
            @endforeach
          </div>

          {{-- Spacing --}}
          <label class="form-label d-block mt-3">Spacing @include('studio.partials.edited-badge', ['keys' => 'social_icons.spacing'])</label>
          <div class="appearance-swatch-group" role="radiogroup">
            @foreach(['tight','normal','loose'] as $spacing)
              <label class="appearance-swatch" data-swatch="social-spacing-{{ $spacing }}">
                <input type="radio" name="social_icons[spacing]" value="{{ $spacing }}" form="appearance-form" @if($saved['social_icons']['spacing'] === $spacing) checked @endif>
                <span class="appearance-swatch-name">{{ ucfirst($spacing) }}</span>
              </label>
            @endforeach
          </div>

          {{-- Background style --}}
          <label class="form-label d-block mt-3">Background style @include('studio.partials.edited-badge', ['keys' => 'social_icons.background_style'])</label>
          <div class="appearance-swatch-group" role="radiogroup">
            @foreach([
              'none'    => 'No background',
              'circle'  => 'Circle',
              'rounded' => 'Rounded square',
              'solid'   => 'Filled brand circle',
            ] as $val => $label)
              <label class="appearance-swatch" data-swatch="social-bg-{{ $val }}">
                <input type="radio" name="social_icons[background_style]" value="{{ $val }}" form="appearance-form" @if($saved['social_icons']['background_style'] === $val) checked @endif>
                <span class="appearance-swatch-name">{{ $label }}</span>
              </label>
            @endforeach
          </div>

          {{-- Hover effect --}}
          <label class="form-label d-block mt-3">Hover effect @include('studio.partials.edited-badge', ['keys' => 'social_icons.hover'])</label>
          <select name="social_icons[hover]" form="appearance-form" class="form-select" style="max-width: 260px;">
            @foreach([
              'none'       => 'None',
              'lift'       => 'Lift up',
              'glow'       => 'Soft glow',
              'scale'      => 'Scale up',
              'colorshift' => 'Color shift',
            ] as $val => $label)
              <option value="{{ $val }}" @if($saved['social_icons']['hover'] === $val) selected @endif>{{ $label }}</option>
            @endforeach
          </select>
        </fieldset>
      </div>

    </div> {{-- /.tab-content --}}

    {{-- Appearance form — inputs across tabs post to it via their
         form="appearance-form" attribute. Edits auto-save to the draft as
         you make them (appearance.js debounce), so there's no manual Save
         button; the Reset-to-theme action stays. --}}
    <form id="appearance-form" action="{{ route('saveAppearance') }}" method="post" class="appearance-save-form" data-preview-css-url="{{ route('previewAppearanceCss') }}">
      @csrf
      <div class="appearance-actions">
        <button type="button" class="btn btn-outline-secondary" id="appearance-reset-btn" data-confirm="Reset your appearance back to the theme's own look? This clears every color, font, shape, and background you changed.">
          <i class="bi bi-arrow-counterclockwise"></i> Reset to theme
        </button>
      </div>
    </form>

    <form id="appearance-reset-form" action="{{ route('resetAppearance') }}" method="post" style="display:none">
      @csrf
    </form>
  </div> {{-- /#appearance-wrap --}}
</section>
