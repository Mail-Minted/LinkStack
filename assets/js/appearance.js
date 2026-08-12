/* Appearance editor — runtime behavior.
   Phase 2 (now): form interactivity (color-picker sync, bg-panel toggle,
   preset quick-click, reset button).
   Phase 4 adds live-preview postMessage here. Kept in one file so the
   eventual preview code lives next to the state it observes. */

(function () {
    'use strict';

    var form = document.getElementById('appearance-form');
    // "wrap" contains the tab nav + all tab panes + the tiny save form.
    // DOM queries for inputs span tabs, so we query from wrap rather
    // than form (inputs live OUTSIDE the form element but are
    // associated via form="appearance-form" attribute).
    var wrap = document.getElementById('appearance-wrap') || form;
    if (!form || !wrap) return;

    // ---------- Color picker <-> hex text input sync ----------

    var pairs = wrap.querySelectorAll('[data-pair]');
    pairs.forEach(function (el) {
        el.addEventListener('input', function () {
            var partner = document.getElementById(el.dataset.pair);
            if (!partner) return;
            var val = el.value;
            // Hex input: uppercase and require 7-char shape before mirroring.
            if (el.classList.contains('appearance-color-hex')) {
                if (/^#[0-9a-fA-F]{6}$/.test(val)) partner.value = val;
            } else {
                partner.value = val;
            }
        });
    });

    // ---------- Color presets (quick-click tiles) ----------

    wrap.querySelectorAll('.appearance-color-preset').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var hexInput = document.getElementById(btn.dataset.target + '-hex');
            var swatch   = document.getElementById(btn.dataset.target);
            if (hexInput) hexInput.value = btn.dataset.preset;
            if (swatch)   swatch.value   = btn.dataset.preset;
            // Fire input events so the live-preview hook (Phase 4) reacts.
            if (hexInput) hexInput.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });

    // ---------- Background type → show matching panel ----------

    wrap.querySelectorAll('.appearance-bg-type').forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (!radio.checked) return;
            wrap.querySelectorAll('[data-bg-panel]').forEach(function (panel) {
                panel.style.display = (panel.dataset.bgPanel === radio.value) ? '' : 'none';
            });
        });
    });

    // ---------- Social icons: custom color picker visibility ----------
    // Shows the custom color picker only when the "Custom color"
    // radio is active. Same pattern as the background-type panels.

    var socialCustomWrap = document.getElementById('social-custom-color-wrap');
    if (socialCustomWrap) {
        wrap.querySelectorAll('input[name="social_icons[color]"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (!radio.checked) return;
                socialCustomWrap.style.display = (radio.value === 'custom') ? '' : 'none';
            });
        });
    }

    // ---------- Button text color: only meaningful for filled buttons ----------
    // Outline/soft buttons draw their text in the button (primary) color, so the
    // "Button text color" control has no visible effect there. Dim it + show its
    // note when the style isn't filled so it doesn't look broken. The value still
    // submits (we don't disable it), so switching back to filled restores it.
    var btnTextWrap = document.getElementById('c-btn-text-wrap');
    if (btnTextWrap) {
        wrap.querySelectorAll('input[name="buttons[style]"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (!radio.checked) return;
                btnTextWrap.classList.toggle('is-inactive', radio.value !== 'filled');
            });
        });
    }

    // ---------- Reset button → confirm + submit reset form ----------

    // The confirm step lives on the button's data-confirm attribute —
    // the sidebar layout's delegated handler gates the click behind an
    // INLINE strip (never window.confirm, which Chrome can suppress).
    // By the time this listener runs, the user already said yes.
    var resetBtn = document.getElementById('appearance-reset-btn');
    var resetForm = document.getElementById('appearance-reset-form');
    if (resetBtn && resetForm) {
        resetBtn.addEventListener('click', function () {
            resetForm.submit();
        });
    }

    // ---------- Live preview: iframe + server-computed CSS ----------
    //
    // Iframe loads /@{user}?preview=1 (same origin → we can write into
    // its document directly). On any form change we fetch the override
    // CSS the current state would publish and push it into a single
    // <style id="user-appearance-preview"> inside the iframe's head.
    // Cascading last beats both the theme CSS and any saved
    // customization-override block.

    var iframe = document.getElementById('appearance-preview-iframe');
    if (!iframe) return;

    var iframeReady = false;

    // ---------- Server-computed preview CSS ----------
    //
    // The form's unsaved state is POSTed (debounced) to the
    // preview-css endpoint, which sparse-diffs it against the theme
    // manifest and returns the exact override CSS a save would
    // publish (built by App\Services\AppearanceCss — the same code
    // that renders the public page, so the preview cannot drift).
    // Untouched knobs emit nothing, so the theme's own styling shows
    // through in the preview exactly as it would after saving. This
    // replaced ~200 lines of duplicated client-side CSS building
    // (Phase 2, THEME-APPEARANCE-PLAN.md).

    var previewCssUrl = form.getAttribute('data-preview-css-url');
    var previewTimer = null;
    var previewSeq = 0;

    function fetchPreviewCss() {
        // FormData(form) collects the form="appearance-form" inputs
        // scattered across the tab panes, same as submitting would.
        var fd = new FormData(form);
        var seq = ++previewSeq;
        fetch(previewCssUrl, {
            method: 'POST',
            body: fd,
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (seq !== previewSeq) return; // superseded by a newer edit
            injectPreview(res.css || '', res.font || null);
            updateEditedBadges(res.keys || []);
        }).catch(function (err) {
            if (window.console) console.warn('[Appearance preview] fetch failed:', err);
        });
    }

    // "edited" chips next to each knob — shown while the knob differs
    // from the theme. The endpoint returns the overridden dot-keys for
    // the form's CURRENT state, so chips appear/disappear live as the
    // user edits (including editing a value back to the theme's own).
    function updateEditedBadges(keys) {
        document.querySelectorAll('[data-mm-edited-key]').forEach(function (el) {
            var mine = el.getAttribute('data-mm-edited-key').split(/\s+/);
            var on = mine.some(function (k) { return keys.indexOf(k) !== -1; });
            el.style.display = on ? '' : 'none';
        });
    }

    function injectPreview(css, fontHref) {
        var doc;
        try { doc = iframe.contentDocument; } catch (e) { return; }
        if (!doc || !doc.head) return;

        // Disable the server-rendered saved-state override so the
        // preview block is the single source of appearance rules.
        var saved = doc.getElementById('user-appearance-override');
        if (saved) saved.disabled = true;

        // Upsert the override style element.
        var styleEl = doc.getElementById('user-appearance-preview');
        if (!styleEl) {
            styleEl = doc.createElement('style');
            styleEl.id = 'user-appearance-preview';
            doc.head.appendChild(styleEl);
        }
        styleEl.textContent = css;

        // Google Font for the selected family, when overridden.
        var fontLinkId = 'user-appearance-preview-font';
        var existing = doc.getElementById(fontLinkId);
        if (fontHref) {
            if (!existing) {
                existing = doc.createElement('link');
                existing.id = fontLinkId;
                existing.rel = 'stylesheet';
                doc.head.appendChild(existing);
            }
            if (existing.getAttribute('href') !== fontHref) existing.setAttribute('href', fontHref);
        } else if (existing) {
            existing.remove();
        }
    }

    function syncPreview() {
        if (!iframeReady || !previewCssUrl) return;
        // Debounce — color pickers fire input continuously while
        // dragging; one request per settled edit is plenty.
        clearTimeout(previewTimer);
        previewTimer = setTimeout(fetchPreviewCss, 180);
    }

    iframe.addEventListener('load', function () {
        iframeReady = true;
        // Deliberately DO NOT syncPreview() here. On load the iframe
        // already shows the true server render — the selected theme,
        // plus the user's saved appearance override if they have one.
        // Injecting the form-derived preview CSS on load would disable
        // that server render and repaint with appearance defaults
        // (white bg, blue buttons, etc.), masking the freshly-selected
        // theme. Injection is deferred until the user actually edits an
        // Appearance control (handlers below), which is the only time a
        // live, unsaved preview is needed.
    });

    // Fire on every input / change so the preview matches the form in real
    // time (colors change within a frame of dragging the picker) AND
    // debounce-save the form to the draft, so appearance edits persist
    // without a manual Save. The first preview event flips the iframe from
    // the server render to the injected preview. Events bubble from inputs
    // across tabs to the shared wrap element (they don't bubble to the
    // <form> because they live outside it in the tab panes).
    function onAppearanceEdit() {
        syncPreview();
        if (window.mmAutoSaveForm) window.mmAutoSaveForm(form, 700);
    }
    wrap.addEventListener('input',  onAppearanceEdit);
    wrap.addEventListener('change', onAppearanceEdit);

    // No unsaved-changes warning: edits auto-save (and go live) as
    // they're made, so there is nothing unsaved to lose.

    // ---------- Tab state persisted in URL hash ----------
    //
    // When the page reloads after a photo upload / bg upload / save,
    // we want the user returned to the tab they were on. Use the URL
    // hash so it survives navigation and is bookmarkable.

    var tabButtons = wrap.querySelectorAll('[data-bs-toggle="pill"]');
    function activateTabById(targetId) {
        if (!targetId) return false;
        var btn = wrap.querySelector('[data-bs-target="' + targetId + '"]');
        if (!btn || !window.bootstrap || !window.bootstrap.Tab) return false;
        window.bootstrap.Tab.getOrCreateInstance(btn).show();
        return true;
    }
    if (location.hash && location.hash.indexOf('#t-') === 0) {
        activateTabById(location.hash);
    }
    tabButtons.forEach(function (btn) {
        btn.addEventListener('shown.bs.tab', function () {
            var target = btn.getAttribute('data-bs-target');
            if (target) {
                // replaceState so we don't litter browser history with a
                // back-stack entry per tab click.
                history.replaceState(null, '', target);
            }
        });
    });

    // ---------- Background image: resize client-side + upload via fetch ----------
    //
    // Lives in the "image" bg panel inside the main form, but the
    // buttons are type="button" so clicking them doesn't submit the
    // main save. We resize in-browser to ≤1920px JPEG q=0.82 then POST
    // to a dedicated endpoint. On success we reload so the live
    // preview iframe picks up the new saved state.

    var bgFile      = document.getElementById('bg-file');
    var bgUploadBtn = document.getElementById('bg-upload-btn');
    var bgRemoveBtn = document.getElementById('bg-remove-btn');
    var bgStatus    = document.getElementById('bg-upload-status');

    function bgSetStatus(msg, kind) {
        if (!bgStatus) return;
        bgStatus.textContent = msg || '';
        bgStatus.className   = 'small mt-2 ' + (kind === 'err' ? 'text-danger' : kind === 'ok' ? 'text-success' : 'text-muted');
    }

    function readCsrf() {
        // The main appearance-form always renders an @csrf hidden input.
        var input = document.querySelector('#appearance-form input[name="_token"]');
        return input ? input.value : '';
    }

    function resizeForBackground(file) {
        // Shared util (mm-image-resize.js): 1920px dimension cap + JPEG
        // quality stepping so the encode always fits the server's 2MB
        // rule. Loaded by the shell before this file.
        return window.mmResizeImage(file, { maxDim: 1920, name: 'background.jpg' });
    }

    if (bgUploadBtn && bgFile) {
        bgUploadBtn.addEventListener('click', function () {
            var f = bgFile.files && bgFile.files[0];
            if (!f) { bgSetStatus('Pick a file first.', 'err'); return; }
            // A zero-byte selection is almost always a cloud-drive placeholder
            // that hasn't synced down yet — catch it before we bother resizing.
            if (f.size === 0) {
                bgSetStatus('That file looks empty. If it\'s in a cloud drive (Google Drive, iCloud, OneDrive), download it to your device first, then upload.', 'err');
                return;
            }
            bgUploadBtn.disabled = true;
            bgSetStatus('Resizing…');
            resizeForBackground(f).then(function (resized) {
                bgSetStatus('Uploading…');
                var fd = new FormData();
                fd.append('image', resized);
                fd.append('_token', readCsrf());
                return fetch('/studio/appearance/background-image', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                });
            }).then(function (resp) {
                if (!resp.ok) {
                    // Laravel's 422 JSON carries the actual reason
                    // ("The image size should not exceed 2MB." etc.) —
                    // show it instead of a bare status code.
                    return resp.json().then(function (j) {
                        bgSetStatus((j && j.message) || ('Upload failed (' + resp.status + ').'), 'err');
                    }).catch(function () {
                        bgSetStatus('Upload failed (' + resp.status + ').', 'err');
                    }).then(function () {
                        bgUploadBtn.disabled = false;
                    });
                }
                bgSetStatus('Uploaded, reloading…', 'ok');
                location.reload();
            }).catch(function (e) {
                bgSetStatus(e.message || 'Error.', 'err');
                bgUploadBtn.disabled = false;
            });
        });
    }

    if (bgRemoveBtn) {
        bgRemoveBtn.addEventListener('click', function () {
            // Confirm handled by the button's data-confirm attribute
            // (inline strip via the sidebar layout) — reaching here
            // means the user already confirmed.
            bgRemoveBtn.disabled = true;
            var fd = new FormData();
            fd.append('_token', readCsrf());
            fetch('/studio/appearance/background-image/remove', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            }).then(function (resp) {
                if (!resp.ok) {
                    bgSetStatus('Remove failed (' + resp.status + ').', 'err');
                    bgRemoveBtn.disabled = false;
                    return;
                }
                location.reload();
            }).catch(function () {
                bgSetStatus('Network error.', 'err');
                bgRemoveBtn.disabled = false;
            });
        });
    }

    // ---------- Profile photo: client-side crop + drag to center ----------
    //
    // User picks a file, we load it into a circular stage that fills on
    // "cover" math (min dimension fits the circle, excess is cropped by
    // overflow:hidden). They can drag to reposition and zoom via slider.
    // On submit we render the same geometry onto a square canvas at a
    // higher output resolution and replace the file input's File with
    // the cropped version — the server never sees the original.

    var photoForm    = document.getElementById('appearance-photo-form');
    var photoFile    = document.getElementById('photo-file');
    var photoStage   = document.getElementById('photo-stage');
    var photoImage   = document.getElementById('photo-stage-image');
    var photoZoom    = document.getElementById('photo-zoom');
    var photoReset   = document.getElementById('photo-reset-btn');
    var photoEdit    = document.getElementById('photo-edit-controls');

    if (!photoForm || !photoFile || !photoStage || !photoImage) return;

    var STAGE_SIZE  = 160;  // must match CSS
    var OUTPUT_SIZE = 512;  // final square JPEG shipped to the server

    // State: the image's displayed size (renderedW/H px) and its
    // top-left position (dx/dy px) inside the stage.
    var photoState = {
        hasNewFile: false,
        naturalW:   0,
        naturalH:   0,
        baseScale:  1,   // scale needed so image's min dimension fills stage
        userScale:  1,   // slider multiplier, 1..3
        dx:         0,
        dy:         0,
        // Decode lifecycle. The submit handler must NEVER let the raw
        // picked file post natively: it bypasses the client-side
        // crop/resize, and a >2MB original just bounces off the
        // server's safety cap ("should not exceed 2MB") — the resize
        // was our promise, not the user's problem.
        pendingDecode:   false,  // picked, still decoding
        decodeFailed:    false,  // picked, undecodable (cloud stub, HEIC, corrupt)
        submitWhenReady: false,  // user hit Upload mid-decode; go when ready
    };

    var photoStatus = document.getElementById('photo-upload-status');
    function photoSetStatus(msg, kind) {
        if (!photoStatus) return;
        photoStatus.textContent = msg || '';
        photoStatus.className = 'small mt-2 ' + (kind === 'err' ? 'text-danger' : 'text-muted');
    }

    function layoutImage() {
        var s = photoState;
        var renderedW = s.naturalW * s.baseScale * s.userScale;
        var renderedH = s.naturalH * s.baseScale * s.userScale;
        photoImage.style.width  = renderedW + 'px';
        photoImage.style.height = renderedH + 'px';

        // Clamp drag so the image always covers the stage (no gaps at the edges).
        var minX = STAGE_SIZE - renderedW;
        var minY = STAGE_SIZE - renderedH;
        if (s.dx > 0) s.dx = 0;
        if (s.dy > 0) s.dy = 0;
        if (s.dx < minX) s.dx = minX;
        if (s.dy < minY) s.dy = minY;

        photoImage.style.left = s.dx + 'px';
        photoImage.style.top  = s.dy + 'px';
        photoImage.style.transform = '';  // we're driving layout, not transform
    }

    function centerImage() {
        var s = photoState;
        var renderedW = s.naturalW * s.baseScale * s.userScale;
        var renderedH = s.naturalH * s.baseScale * s.userScale;
        s.dx = (STAGE_SIZE - renderedW) / 2;
        s.dy = (STAGE_SIZE - renderedH) / 2;
        layoutImage();
    }

    photoFile.addEventListener('change', function () {
        var f = photoFile.files && photoFile.files[0];
        photoState.submitWhenReady = false;
        photoState.decodeFailed = false;
        photoSetStatus('');
        if (!f) {
            // user cleared the picker: hide the stage again
            photoStage.style.display = 'none';
            photoState.hasNewFile = false;
            photoState.pendingDecode = false;
            if (photoEdit) photoEdit.style.display = 'none';
            return;
        }

        photoState.hasNewFile = false;
        photoState.pendingDecode = true;
        var url = URL.createObjectURL(f);
        var tmp = new Image();
        tmp.onload = function () {
            photoImage.classList.remove('appearance-photo-stage-image-logo');
            photoImage.src = url;
            photoState.hasNewFile = true;
            photoState.pendingDecode = false;
            photoState.naturalW = tmp.naturalWidth;
            photoState.naturalH = tmp.naturalHeight;
            // "cover" the stage: use the larger required scale.
            photoState.baseScale = Math.max(STAGE_SIZE / tmp.naturalWidth, STAGE_SIZE / tmp.naturalHeight);
            photoState.userScale = 1;
            if (photoZoom) photoZoom.value = '100';
            photoStage.style.display = '';   // reveal when a file has been picked
            centerImage();
            photoStage.classList.add('is-draggable');
            if (photoEdit) photoEdit.style.display = '';
            // User already clicked Upload while we were decoding —
            // resume the submit now that the crop stage is ready.
            if (photoState.submitWhenReady) {
                photoState.submitWhenReady = false;
                photoSetStatus('');
                if (typeof photoForm.requestSubmit === 'function') {
                    photoForm.requestSubmit();
                } else {
                    photoForm.dispatchEvent(new Event('submit', { cancelable: true }));
                }
            }
        };
        tmp.onerror = function () {
            URL.revokeObjectURL(url);
            photoState.pendingDecode = false;
            photoState.decodeFailed = true;
            photoState.submitWhenReady = false;
            photoSetStatus('Couldn\'t read that image. If it\'s in a cloud drive (Google Drive, iCloud, OneDrive), download it to your device first, then upload. HEIC photos need converting to JPG.', 'err');
        };
        tmp.src = url;
    });

    if (photoZoom) {
        photoZoom.addEventListener('input', function () {
            if (!photoState.hasNewFile) return;
            photoState.userScale = parseFloat(photoZoom.value) / 100;
            layoutImage();
        });
    }
    if (photoReset) {
        photoReset.addEventListener('click', function () {
            if (!photoState.hasNewFile) return;
            photoState.userScale = 1;
            if (photoZoom) photoZoom.value = '100';
            centerImage();
        });
    }

    // Drag handling (pointer events so mouse + touch both work).
    var dragOrigin = null;
    photoStage.addEventListener('pointerdown', function (e) {
        if (!photoState.hasNewFile) return;
        dragOrigin = { x: e.clientX - photoState.dx, y: e.clientY - photoState.dy };
        photoStage.setPointerCapture(e.pointerId);
        photoStage.classList.add('is-dragging');
    });
    photoStage.addEventListener('pointermove', function (e) {
        if (!dragOrigin) return;
        photoState.dx = e.clientX - dragOrigin.x;
        photoState.dy = e.clientY - dragOrigin.y;
        layoutImage();
    });
    var endDrag = function (e) {
        if (!dragOrigin) return;
        dragOrigin = null;
        photoStage.classList.remove('is-dragging');
        try { photoStage.releasePointerCapture(e.pointerId); } catch (_) {}
    };
    photoStage.addEventListener('pointerup', endDrag);
    photoStage.addEventListener('pointercancel', endDrag);

    // Render the stage's visible crop to a canvas and replace the file
    // input's File before letting the form submit.
    photoForm.addEventListener('submit', function (e) {
        if (!photoState.hasNewFile) {
            var picked = photoFile.files && photoFile.files[0];
            if (picked && photoState.pendingDecode) {
                // Clicked Upload before the photo finished decoding.
                // Hold the submit — posting the RAW file would skip the
                // client-side resize and a big original just bounces
                // off the server's 2MB cap. onload resumes us.
                e.preventDefault();
                photoState.submitWhenReady = true;
                photoSetStatus('Preparing your photo…');
                return;
            }
            if (picked) {
                // Decode failed (or never started): the raw file is the
                // only thing we could post, and we promised not to.
                e.preventDefault();
                if (!photoState.decodeFailed) {
                    photoSetStatus('Couldn\'t read that image — try a JPG, PNG, or WebP saved on this device.', 'err');
                }
                return;
            }
            return; // no file picked: native submit shows the required-field error
        }
        e.preventDefault();

        var ratio = OUTPUT_SIZE / STAGE_SIZE;
        var canvas = document.createElement('canvas');
        canvas.width  = OUTPUT_SIZE;
        canvas.height = OUTPUT_SIZE;
        var ctx = canvas.getContext('2d');

        // Transparency: JPEG can't carry alpha and a bare export turns
        // transparent pixels BLACK. Logo-style sources keep their
        // transparency as PNG (512px logos are tiny); opaque photos get
        // a white base + JPEG, so nothing can ever flatten to black.
        var srcType  = (photoFile.files[0] && photoFile.files[0].type) || '';
        var keepAlpha = !!(window.mmImageHasAlpha && window.mmImageHasAlpha(photoImage, srcType));
        if (!keepAlpha) {
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, OUTPUT_SIZE, OUTPUT_SIZE);
        }

        var s = photoState;
        var rw = s.naturalW * s.baseScale * s.userScale;
        var rh = s.naturalH * s.baseScale * s.userScale;
        ctx.drawImage(photoImage, s.dx * ratio, s.dy * ratio, rw * ratio, rh * ratio);

        canvas.toBlob(function (blob) {
            if (!blob) { photoForm.submit(); return; }
            var originalName = (photoFile.files[0] && photoFile.files[0].name) || 'avatar.jpg';
            var baseName = originalName.replace(/\.[^.]+$/, '');
            var file = keepAlpha
                ? new File([blob], baseName + '.png', { type: 'image/png' })
                : new File([blob], baseName + '.jpg', { type: 'image/jpeg' });
            var dt = new DataTransfer();
            dt.items.add(file);
            photoFile.files = dt.files;
            // Now submit natively so the browser does the multipart upload.
            HTMLFormElement.prototype.submit.call(photoForm);
        }, keepAlpha ? 'image/png' : 'image/jpeg', 0.92);
    });
})();
