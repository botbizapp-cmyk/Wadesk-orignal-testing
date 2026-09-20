/**
 * Facebook Posts composer + grid (user.facebook.posts / user.facebook.my-posts).
 *
 * A faithful port of the Instagram post composer's client behaviour, adapted to
 * Facebook's real post types (photo / album / link / video / reel):
 *   • Post-type tabs — switch the visible media section + set the reel flag.
 *   • Media upload thumbnails preview.
 *   • Message character counter.
 *   • Publish-now / Schedule-for-later toggle → reveals the datetime field.
 *   • AI composer tools (caption / repurpose / review / best-time / image).
 *   • Grid preview modal for /facebook/my-posts.
 *
 * Pure DOM wiring, no inline <script> in the blade. Delete / publish confirm
 * dialogs are handled globally by lib/ui-modal (data-confirm). Loaded via
 * PAGE_INITIALIZERS when <body data-page="user-facebook-posts"> or
 * "user-facebook-posts-grid".
 */
export default function () {
    wireComposer();
    wirePreview();
    wireGrid();
}

/* =========================================================================
 |  Live preview — mirrors the composer into a Facebook-style post card.
 ========================================================================= */
function wirePreview() {
    const form = document.querySelector('[data-fb-composer]');
    const box = document.querySelector('[data-fb-preview]');
    if (!form || !box) return;

    const nameEl  = box.querySelector('[data-prev-name]');
    const avaEl   = box.querySelector('[data-prev-avatar]');
    const msgEl   = box.querySelector('[data-prev-msg]');
    const emptyEl = box.querySelector('[data-prev-msg-empty]');
    const mediaEl = box.querySelector('[data-prev-media]');

    const pageSel   = form.querySelector('#fb-page');
    const message   = form.querySelector('#fb-message');
    const fileInput = form.querySelector('#fb-photos');
    const photoUrls = form.querySelector('#fb-photo-urls');
    const linkInput = form.querySelector('[data-fb-media="link"] input[name="link"]');
    const videoInput= form.querySelector('#fb-video');
    const videoUrl  = form.querySelector('input[name="video_url"]');

    const syncName = () => {
        if (!pageSel) return;
        const opt = pageSel.options[pageSel.selectedIndex];
        const name = (opt && opt.text ? opt.text : 'Your Page').trim();
        if (nameEl) nameEl.textContent = name;
        if (avaEl) avaEl.textContent = (name.charAt(0) || 'F').toUpperCase();
    };
    const syncMsg = () => {
        const v = (message && message.value ? message.value : '').trim();
        if (msgEl) msgEl.textContent = v;
        if (emptyEl) emptyEl.classList.toggle('hidden', v !== '');
    };
    const setMedia = (html) => {
        if (!mediaEl) return;
        if (html) { mediaEl.innerHTML = html; mediaEl.classList.remove('hidden'); }
        else { mediaEl.innerHTML = ''; mediaEl.classList.add('hidden'); }
    };
    const firstUrl = (s) => String(s || '').split(/[\s,]+/).map((x) => x.trim()).filter(Boolean)[0] || '';
    const hostOf = (u) => { try { return new URL(u).host; } catch (e) { return u; } };

    const syncMedia = () => {
        if (fileInput && fileInput.files && fileInput.files.length) {
            return setMedia('<img src="' + URL.createObjectURL(fileInput.files[0]) + '" class="w-full max-h-[340px] object-cover" alt="">');
        }
        const pu = firstUrl(photoUrls && photoUrls.value);
        if (pu) return setMedia('<img src="' + esc(pu) + '" referrerpolicy="no-referrer" class="w-full max-h-[340px] object-cover" alt="">');
        const lk = (linkInput && linkInput.value ? linkInput.value : '').trim();
        if (lk) return setMedia('<div class="px-4 py-3"><div class="text-[10px] font-mono uppercase text-ink-500 tracking-wide">' + esc(hostOf(lk)) + '</div><div class="text-[12.5px] text-ink-800 truncate mt-0.5">' + esc(lk) + '</div></div>');
        const vurl = (videoUrl && videoUrl.value ? videoUrl.value : '').trim();
        if ((videoInput && videoInput.files && videoInput.files.length) || vurl) {
            return setMedia('<div class="aspect-video grid place-items-center text-white/80" style="background:#111"><svg viewBox="0 0 24 24" class="w-11 h-11" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></div>');
        }
        setMedia('');
    };

    if (pageSel) pageSel.addEventListener('change', syncName);
    if (message) message.addEventListener('input', syncMsg);
    if (fileInput) fileInput.addEventListener('change', syncMedia);
    if (photoUrls) photoUrls.addEventListener('input', syncMedia);
    if (linkInput) linkInput.addEventListener('input', syncMedia);
    if (videoInput) videoInput.addEventListener('change', syncMedia);
    if (videoUrl) videoUrl.addEventListener('input', syncMedia);
    // Re-evaluate media when the post type changes (media section swaps).
    form.querySelectorAll('.fb-type-btn').forEach((b) => b.addEventListener('click', () => setTimeout(syncMedia, 0)));

    syncName(); syncMsg(); syncMedia();
}

/* =========================================================================
 |  Composer
 ========================================================================= */
function wireComposer() {
    const form = document.querySelector('[data-fb-composer]');
    if (!form) return;

    const asReel   = form.querySelector('#fb-as-reel');
    const typeHint = form.querySelector('#fb-type-hint');
    const mediaSections = {
        photo: form.querySelector('[data-fb-media="photo"]'),
        link:  form.querySelector('[data-fb-media="link"]'),
        video: form.querySelector('[data-fb-media="video"]'),
    };
    const reelNote  = form.querySelector('[data-fb-reel-note]');
    const videoNote = form.querySelector('[data-fb-video-note]');

    // Which media section (and reel flag) each post type uses.
    const typeMap = {
        photo:       { section: 'photo', reel: false },
        multi_photo: { section: 'photo', reel: false },
        link:        { section: 'link',  reel: false },
        video:       { section: 'video', reel: false },
        reel:        { section: 'video', reel: true },
    };

    let activeType = 'photo';

    const clearSection = (el) => {
        if (!el) return;
        el.querySelectorAll('input, textarea').forEach((f) => { f.value = ''; });
    };

    const showSection = (name) => {
        Object.keys(mediaSections).forEach((k) => {
            const el = mediaSections[k];
            if (!el) return;
            const on = k === name;
            // Clear the inputs of a section we're leaving so a hidden field never
            // contaminates Facebook's post-type auto-detection (photos vs link vs
            // video). Only clear when the section is actually being hidden.
            if (!on && !el.classList.contains('hidden')) clearSection(el);
            el.classList.toggle('hidden', !on);
        });
        if (name !== 'photo' && thumbs) { thumbs.innerHTML = ''; thumbs.classList.add('hidden'); }
    };

    const setType = (btn) => {
        const t = btn.dataset.type;
        activeType = t;
        const map = typeMap[t] || typeMap.photo;
        form.querySelectorAll('.fb-type-btn').forEach((b) => {
            const on = b === btn;
            b.classList.toggle('border-wa-deep', on);
            b.classList.toggle('bg-wa-deep/5', on);
            b.classList.toggle('text-wa-deep', on);
            b.classList.toggle('border-paper-200', !on);
            b.classList.toggle('text-ink-600', !on);
        });
        showSection(map.section);
        if (asReel) asReel.value = map.reel ? '1' : '0';
        if (reelNote) reelNote.hidden = !map.reel;
        if (videoNote) videoNote.hidden = map.reel;
        if (typeHint) typeHint.textContent = btn.dataset.hint || '';
    };

    form.querySelectorAll('.fb-type-btn').forEach((btn) => {
        btn.addEventListener('click', () => setType(btn));
    });

    // ── Media thumbnails preview ──────────────────────────────────────────
    const fileInput = form.querySelector('#fb-photos');
    const thumbs = form.querySelector('#fb-thumbs');
    const renderThumbs = (files) => {
        if (!thumbs) return;
        thumbs.innerHTML = '';
        if (!files || !files.length) { thumbs.classList.add('hidden'); return; }
        thumbs.classList.remove('hidden');
        Array.prototype.slice.call(files).forEach((f) => {
            const url = URL.createObjectURL(f);
            const wrap = document.createElement('span');
            wrap.className = 'relative w-16 h-16 rounded-lg overflow-hidden border border-paper-200 bg-paper-100';
            wrap.innerHTML = '<img src="' + url + '" class="w-full h-full object-cover" alt="">';
            thumbs.appendChild(wrap);
        });
    };
    if (fileInput) fileInput.addEventListener('change', () => renderThumbs(fileInput.files));

    // ── Message character counter ─────────────────────────────────────────
    const message = form.querySelector('#fb-message');
    const msgCount = form.querySelector('#fb-msg-count');
    const syncCount = () => { if (msgCount && message) msgCount.textContent = String((message.value || '').length); };
    if (message) message.addEventListener('input', syncCount);
    syncCount();

    // ── Publish now / Schedule for later ──────────────────────────────────
    const scheduleFlag = form.querySelector('#fb-schedule');
    const laterBox = form.querySelector('#fb-when-later');
    const subLabel = form.querySelector('#fb-submit-label');
    const schedAt = form.querySelector('#fb-scheduled-at');
    const lblNow = subLabel ? subLabel.textContent : 'Publish';
    form.querySelectorAll('.fb-when-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            const later = btn.dataset.when === 'later';
            form.querySelectorAll('.fb-when-btn').forEach((b) => {
                const on = b === btn;
                b.classList.toggle('border-wa-deep', on);
                b.classList.toggle('bg-wa-deep/5', on);
                b.classList.toggle('text-wa-deep', on);
                b.classList.toggle('border-paper-200', !on);
                b.classList.toggle('text-ink-600', !on);
            });
            if (laterBox) laterBox.classList.toggle('hidden', !later);
            if (scheduleFlag) scheduleFlag.value = later ? '1' : '0';
            if (subLabel) subLabel.textContent = later ? 'Schedule post' : lblNow;
            if (!later && schedAt) schedAt.value = '';
        });
    });

    // ── AI composer tools ─────────────────────────────────────────────────
    wireAi(form, () => activeType, message, syncCount);
}

/* =========================================================================
 |  AI composer tools
 ========================================================================= */
function wireAi(form, getType, message, syncCount) {
    const box = form.querySelector('#fb-ai');
    if (!box) return;

    const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    const out = box.querySelector('#fb-ai-out');
    const imgRow = box.querySelector('#fb-ai-imgrow');
    const photoUrls = form.querySelector('#fb-photo-urls');
    const thumbs = form.querySelector('#fb-thumbs');

    const setOut = (txt, isErr) => {
        if (!out) return;
        out.classList.remove('hidden');
        out.textContent = txt;
        out.style.color = isErr ? '#b4232a' : '';
    };

    const call = (url, body) =>
        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body || {}),
        }).then((r) => r.json().then((j) => ({ ok: r.ok, j })));

    box.querySelectorAll('.fb-ai-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            const kind = btn.dataset.ai;
            if (kind === 'image') { if (imgRow) imgRow.classList.toggle('hidden'); return; }

            const busy = btn.textContent;
            btn.disabled = true;
            btn.textContent = '…';
            setOut('Working…');
            const done = () => { btn.disabled = false; btn.textContent = busy; };

            if (kind === 'caption') {
                call(box.dataset.captionUrl, { notes: (message && message.value.trim()) || '', media_type: getType() }).then((r) => {
                    if (r.j.ok) { if (message) message.value = r.j.text; syncCount(); setOut('Post drafted — edit it as you like.'); }
                    else setOut(r.j.error || 'Could not generate.', true);
                    done();
                }).catch(() => { setOut('Network error.', true); done(); });
            } else if (kind === 'repurpose') {
                call(box.dataset.repurposeUrl, { caption: (message && message.value.trim()) || '' }).then((r) => {
                    if (r.j.ok) { if (message) message.value = r.j.text; syncCount(); setOut('Post rewritten.'); }
                    else setOut(r.j.error || 'Could not rewrite.', true);
                    done();
                }).catch(() => { setOut('Network error.', true); done(); });
            } else if (kind === 'review') {
                call(box.dataset.reviewUrl, { caption: (message && message.value.trim()) || '' }).then((r) => {
                    setOut(r.j.ok ? r.j.text : (r.j.error || 'Could not review.'), !r.j.ok);
                    done();
                }).catch(() => { setOut('Network error.', true); done(); });
            } else if (kind === 'best-time') {
                call(box.dataset.besttimeUrl, {}).then((r) => {
                    if (r.j.ok) {
                        const lines = (r.j.windows || []).map((w) => '• ' + w.time + ' — ' + w.why);
                        setOut((r.j.note || '') + '\n' + lines.join('\n'));
                    } else setOut(r.j.error || 'Could not compute.', true);
                    done();
                }).catch(() => { setOut('Network error.', true); done(); });
            }
        });
    });

    const imgGen = box.querySelector('#fb-ai-imggen');
    const promptEl = box.querySelector('#fb-ai-prompt');
    if (imgGen) {
        imgGen.addEventListener('click', () => {
            const p = (promptEl && promptEl.value.trim()) || '';
            if (!p) { setOut('Describe the image first.', true); return; }
            const busy = imgGen.textContent;
            imgGen.disabled = true;
            imgGen.textContent = '…';
            setOut('Generating image — this can take ~15s…');
            call(box.dataset.imageUrl, { prompt: p, size: '1024x1024' }).then((r) => {
                if (r.j.ok && r.j.url) {
                    // Switch to the Photo type first (clears other media sections),
                    // then attach the generated image as a pasted photo URL + preview.
                    const photoBtn = form.querySelector('.fb-type-btn[data-type="photo"]');
                    if (photoBtn) photoBtn.click();
                    if (photoUrls) photoUrls.value = (photoUrls.value ? photoUrls.value.trim() + '\n' : '') + r.j.url;
                    if (thumbs) {
                        thumbs.classList.remove('hidden');
                        const wrap = document.createElement('span');
                        wrap.className = 'relative w-16 h-16 rounded-lg overflow-hidden border border-paper-200 bg-paper-100';
                        wrap.innerHTML = '<img src="' + r.j.url + '" class="w-full h-full object-cover" alt="">';
                        thumbs.appendChild(wrap);
                    }
                    setOut('Image generated and attached as a photo. Publish or schedule the post.');
                } else setOut(r.j.error || 'Could not generate the image.', true);
                imgGen.disabled = false;
                imgGen.textContent = busy;
            }).catch(() => { setOut('Network error.', true); imgGen.disabled = false; imgGen.textContent = busy; });
        });
    }
}

/* =========================================================================
 |  Grid preview modal (/facebook/my-posts)
 ========================================================================= */
function wireGrid() {
    const root = document.getElementById('fb-grid');
    const modal = document.getElementById('fb-post-modal');
    if (!root || !modal) return;

    const elMedia = document.getElementById('fb-post-media');
    const elMsg = document.getElementById('fb-post-message');
    const elWhen = document.getElementById('fb-post-when');
    const elLink = document.getElementById('fb-post-permalink');
    const elOpen = document.getElementById('fb-post-open');

    const agoLong = (iso) => {
        const d = new Date(iso);
        if (isNaN(d)) return '';
        const s = Math.max(1, (Date.now() - d.getTime()) / 1000);
        const u = (n, w) => n + ' ' + w + (n === 1 ? '' : 's') + ' ago';
        if (s < 60) return 'Just now';
        if (s < 3600) return u(Math.floor(s / 60), 'minute');
        if (s < 86400) return u(Math.floor(s / 3600), 'hour');
        if (s < 604800) return u(Math.floor(s / 86400), 'day');
        return d.toLocaleDateString(undefined, { month: 'long', day: 'numeric' });
    };

    const openPost = (p) => {
        if (elMedia) {
            elMedia.innerHTML = p.image
                ? '<img src="' + esc(p.image) + '" alt="" referrerpolicy="no-referrer" class="max-w-full max-h-full object-contain">'
                : '<div class="text-white/60 text-[12px] p-6 text-center">No image</div>';
        }
        if (elMsg) elMsg.textContent = p.message || '';
        if (elWhen) elWhen.textContent = p.created ? agoLong(p.created) : '';
        if (elLink) elLink.href = p.permalink || '#';
        if (elOpen) elOpen.href = p.permalink || '#';
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    };
    const closePost = () => {
        modal.classList.add('hidden');
        document.body.style.overflow = '';
        if (elMedia) elMedia.innerHTML = '';
    };

    document.querySelectorAll('.fb-post-tile').forEach((t) => {
        t.addEventListener('click', () => { try { openPost(JSON.parse(t.dataset.post)); } catch (_) { /* ignore */ } });
    });
    modal.querySelectorAll('[data-close-post]').forEach((b) => b.addEventListener('click', closePost));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) closePost(); });
}

function esc(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : s;
    return d.innerHTML;
}
