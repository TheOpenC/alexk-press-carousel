// =============================================
// ALEXK PRESS CAROUSEL
// =============================================

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', onDomReady);
} else {
  onDomReady();
}

function onDomReady() {
  const carousel = document.querySelector('.alexk-press-carousel[data-slides]');
  if (!carousel) return;

  const slides = parseSlidesData(carousel);
  if (!slides.length) return;

  const state = {
    allSlides:    slides.slice(),
    deck:         [],
    lastShown:    null,
    transitioning: false, // guard against clicks during decode()
  };

  const linkBar = carousel.querySelector('.alexk-press-link-bar');
  const linkEl  = carousel.querySelector('.alexk-press-link');

  // Apply orientation to PHP-rendered first slide immediately.
  // The PHP slide already has data-orientation set from img_w/img_h (see PHP shortcode),
  // so this is mainly for the fallback load-event path on old cached pages.
  applyOrientationAttributes(carousel.querySelector('.alexk-press-slide'));
  updatePressLink(linkEl, slides[0].press_url);

  // Detect which slide PHP actually rendered by reading fallback src attributes
  // from the DOM. Cannot rely on slides[0] — page caching can cause data-slides
  // order to diverge from what PHP rendered, which was permanently excluding the
  // wrong slide and leaving only 2 items in rotation.
  //
  // Keep ALL slides in allSlides; set lastShown so the no-repeat shuffle logic
  // avoids immediately repeating the visible slide on first click.
  const firstSlideEl = carousel.querySelector('.alexk-press-slide');
  const renderedFallbacks = firstSlideEl
    ? Array.from(firstSlideEl.querySelectorAll('.alexk-press-image')).map(img => img.getAttribute('src'))
    : [];
  state.lastShown = JSON.stringify(renderedFallbacks);
  state.allSlides = slides.slice();

  // Preload the next slide while the user reads the first
  preloadSlide(peekNextSlide(state));

  // Click anywhere on carousel (except link bar) → advance
  carousel.addEventListener('click', function (event) {
    if (linkBar && linkBar.contains(event.target)) return;
    if (state.transitioning) return;
    advanceSlide(carousel, state);
  });

  carousel._alexkPressCarousel = { state, linkEl };
  installKeyboardNavigation(carousel);
  applySafariSelectionGuard(carousel);
}

// ---- SLIDE NAVIGATION ----

function advanceSlide(carousel, state) {
  const slide = getNextSlide(state);
  if (!slide) return;
  state.transitioning = true;
  renderSlide(carousel, slide).then(() => {
    state.transitioning = false;
    // Preload whatever's next while the user looks at the new slide
    preloadSlide(peekNextSlide(state));
  });
}

// ---- RENDER: decode-before-swap ----
//
// Problem: a plain innerHTML swap shows a blank frame (or partial frames on
// multi-image slides) because:
//   1. data-orientation wasn't set → picture snaps width after load
//   2. height collapses to 0 then expands as images pop in
//   3. decoding="async" lets the browser paint a blank slot while decoding
//
// Fix: build the new slide in an offscreen scratch node attached to the real
// DOM (so <source> srcset evaluation uses actual viewport metrics), call
// img.decode() on every image (resolves when that image is ready to composite
// to screen — uses the preloaded cache so this is typically < 1 frame), then
// move the fully-decoded nodes into the live slideEl in one synchronous step.
// The browser paints them all at once with no blank intermediate frame.

async function renderSlide(carousel, slide) {
  const slideEl = carousel.querySelector('.alexk-press-slide');
  if (!slideEl) return;

  // ---- 1. Freeze height to prevent collapse during swap ----
  // Especially important going from 3-image grouped slide → single image.
  const frozenH = slideEl.offsetHeight;
  if (frozenH > 0) slideEl.style.minHeight = frozenH + 'px';

  // ---- 2. Build in offscreen scratch node ----
  // Must be attached to the document so the browser can evaluate <source>
  // media queries / sizes against real viewport dimensions and choose the
  // correct srcset candidate before decode().
  const scratch = document.createElement('div');
  scratch.setAttribute('aria-hidden', 'true');
  Object.assign(scratch.style, {
    position:      'absolute',
    left:          '-9999px',
    top:           '0',
    // Match the carousel's actual rendered width so srcset candidate selection
    // picks the same image file it will pick once in the live layout.
    width:         slideEl.offsetWidth + 'px',
    visibility:    'hidden',
    pointerEvents: 'none',
    overflow:      'hidden',
  });
  scratch.innerHTML = buildSlideHTML(slide);
  document.body.appendChild(scratch);

  // ---- 3. Decode all images ----
  // img.decode() returns a Promise that resolves when the image is fully
  // decoded and safe to display without a paint flash. For preloaded (cached)
  // images this typically completes in well under one frame.
  // 500ms timeout ensures we never block forever on a slow/missing image.
  const imgs = Array.from(scratch.querySelectorAll('.alexk-press-image'));
  await Promise.all(imgs.map(img =>
    Promise.race([
      img.decode().catch(() => {}),
      new Promise(r => setTimeout(r, 500)),
    ])
  ));

  // ---- 4. Move decoded nodes into live slideEl ----
  // Moving actual DOM nodes (vs innerHTML copy) preserves the decoded/loaded
  // state — the browser doesn't re-fetch or re-decode.
  slideEl.innerHTML = '';
  while (scratch.firstChild) slideEl.appendChild(scratch.firstChild);
  document.body.removeChild(scratch);

  // ---- 5. Orientation + link bar ----
  // img_w/img_h from PHP means data-orientation is already set in the HTML
  // (see buildSlideHTML). applyOrientationAttributes is a no-op for those
  // elements and only fires for any image that didn't have dimension data.
  applyOrientationAttributes(slideEl);
  updatePressLink(carousel.querySelector('.alexk-press-link'), slide.press_url);

  // ---- 6. Release height lock ----
  slideEl.style.minHeight = '';
}

// ---- SLIDE HTML BUILDER ----
//
// Uses img_w / img_h (added to PHP JSON) to:
//   • Set data-orientation immediately in the HTML string, before any load
//     event fires — eliminates the width-snap flash on portrait images.
//   • Set aspect-ratio on the <img> — reserves the correct height in the
//     layout before the image has loaded, preventing the height-collapse flash.
//   • fetchpriority="high" — tells the browser to prioritise decoding these
//     images over background work.

function buildSlideHTML(slide) {
  return slide.images.map(img => {
    const w = img.img_w || 0;
    const h = img.img_h || 0;
    const orientation = (w && h) ? (w >= h ? 'landscape' : 'portrait') : '';
    const orientAttr  = orientation ? ` data-orientation="${orientation}"` : '';

    // Cap the picture at its native pixel width so small images are never
    // upscaled. CSS max-width on .alexk-press-picture handles the layout
    // maximum (900px portrait / 1400px landscape) — this inline style adds
    // a tighter native-resolution cap on top of that.
    const nativeCapStyle = w ? ` style="max-width:min(${w}px,100%)"` : '';

    // Inline aspect-ratio on the img reserves space before decode completes,
    // preventing height-collapse flash during slide swap.
    const aspectRatio = (w && h) ? `aspect-ratio:${w}/${h};` : '';

    // sizes: cap the retina hint at the native width so the browser never
    // requests a derivative larger than what was generated. For a 448px image
    // "(min-resolution: 2dppx) 2800px" would request a non-existent file;
    // capping at native width lets the browser fall back cleanly.
    const maxSrcW = Math.min(w || 1400, 1400);
    const SIZES = `(min-resolution: 2dppx) ${maxSrcW}px, (max-width: 1400px) 100vw, 1400px`;

    return `
    <picture class="alexk-press-picture"${orientAttr}${nativeCapStyle}>
      ${img.webp_srcset ? `<source type="image/webp" srcset="${escAttr(img.webp_srcset)}" sizes="${SIZES}">` : ''}
      ${img.jpg_srcset  ? `<source type="image/jpeg" srcset="${escAttr(img.jpg_srcset)}"  sizes="${SIZES}">` : ''}
      <img class="alexk-press-image"
           src="${escAttr(img.fallback)}"
           alt="${escAttr(img.alt || '')}"
           sizes="${SIZES}"
           style="${aspectRatio}"
           fetchpriority="high"
           loading="eager"
           decoding="async">
    </picture>`;
  }).join('');
}

// ---- ORIENTATION ----
//
// Sets data-orientation on .alexk-press-picture after naturalWidth/Height
// are known. Skips elements where PHP already provided the orientation via
// img_w/img_h (data-orientation already present in the HTML).

function applyOrientationAttributes(slideEl) {
  if (!slideEl) return;
  slideEl.querySelectorAll('.alexk-press-image').forEach(img => {
    const fallbackSrc = img.getAttribute('src');

    const tryFallback = () => {
      if (img.currentSrc && img.currentSrc !== fallbackSrc) {
        console.warn('[alexk-press] Broken image, falling back to JPEG:', img.currentSrc.split('/').pop());
        const picture = img.closest('picture');
        if (picture) picture.querySelectorAll('source').forEach(s => s.remove());
        img.removeAttribute('srcset');
        img.src = fallbackSrc;
      }
    };

    const set = () => {
      if (!img.naturalWidth || !img.naturalHeight) {
        tryFallback();
        return;
      }
      const picture = img.closest('.alexk-press-picture');
      if (!picture) return;
      // Skip if orientation was already set from PHP dimension data —
      // no need to wait for load and no risk of a snap-reflow.
      if (picture.dataset.orientation) return;
      picture.dataset.orientation = img.naturalWidth >= img.naturalHeight ? 'landscape' : 'portrait';
    };

    img.addEventListener('error', () => {
      console.warn('[alexk-press] Image error, falling back:', img.currentSrc || img.src);
      const picture = img.closest('picture');
      if (picture) picture.querySelectorAll('source').forEach(s => s.remove());
      img.removeAttribute('srcset');
      img.src = fallbackSrc;
    }, { once: true });

    if (img.complete) set();
    else img.addEventListener('load', set, { once: true });
  });
}

// ---- DECK / STATE ----

function getNextSlide(state) {
  ensureDeckReady(state);
  const next = state.deck.pop();
  if (next) state.lastShown = JSON.stringify(next.images.map(i => i.fallback));
  return next || null;
}

function peekNextSlide(state) {
  ensureDeckReady(state);
  return state.deck.length ? state.deck[state.deck.length - 1] : null;
}

function ensureDeckReady(state) {
  if (!state.deck || state.deck.length === 0) {
    let tries = 0;
    do {
      state.deck = state.allSlides.slice();
      shuffleInPlace(state.deck);
      tries++;
      if (tries > 10) break;
    } while (
      state.lastShown &&
      state.deck.length > 1 &&
      JSON.stringify(state.deck[state.deck.length - 1].images.map(i => i.fallback)) === state.lastShown
    );
  }
}

// ---- PRELOAD ----
//
// Uses <link rel=preload imagesrcset imagesizes> which mirrors exactly what
// <source type="image/webp"> selects — the browser fetches the right file
// and no "preloaded resource not used" warning fires.
// Falls back gracefully on Firefox (imagesrcset not yet supported — ignored).
// For grouped slides (multiple stacked images), all images are preloaded.

function preloadSlide(slide) {
  if (!slide || !slide.images) return;

  document.querySelectorAll('link[data-alexk-press-preload]').forEach(l => l.remove());

  slide.images.forEach((imgData, i) => {
    const link = document.createElement('link');
    link.rel = 'preload';
    link.as  = 'image';
    link.dataset.alexkPressPreload = String(i);

    // Match the sizes string used in buildSlideHTML so the browser preloads
    // the exact same srcset candidate it will display — including native width cap.
    const maxSrcW = Math.min(imgData.img_w || 1400, 1400);
    const SIZES = `(min-resolution: 2dppx) ${maxSrcW}px, (max-width: 1400px) 100vw, 1400px`;

    if (imgData.webp_srcset) {
      link.setAttribute('imagesrcset', imgData.webp_srcset);
      link.setAttribute('imagesizes',  SIZES);
    } else if (imgData.jpg_srcset) {
      link.setAttribute('imagesrcset', imgData.jpg_srcset);
      link.setAttribute('imagesizes',  SIZES);
    } else if (imgData.fallback) {
      link.href = imgData.fallback;
    }

    document.head.appendChild(link);
  });
}

// ---- LINK BAR ----

function updatePressLink(linkEl, press_url) {
  if (!linkEl) return;
  const bar = linkEl.closest('.alexk-press-link-bar');
  if (press_url) {
    linkEl.href = press_url;
    if (bar) bar.style.visibility = 'visible';
  } else {
    linkEl.href = '#';
    if (bar) bar.style.visibility = 'hidden';
  }
}

// ---- KEYBOARD ----

function installKeyboardNavigation(carouselEl) {
  if (document.__alexkPressKeyboardNavInstalled) return;
  document.__alexkPressKeyboardNavInstalled = true;
  document.addEventListener('keydown', (event) => {
    const store = carouselEl?._alexkPressCarousel;
    if (!store) return;
    if (isTypingContext(event)) return;
    const key = event.key;
    if (key === 'ArrowRight' || key === ' ') {
      if (key === ' ') event.preventDefault();
      if (!store.state.transitioning) advanceSlide(carouselEl, store.state);
    }
  }, { passive: false });
}

function isTypingContext(event) {
  if (!event) return true;
  if (event.altKey || event.ctrlKey || event.metaKey) return true;
  const t = event.target;
  if (!t || !(t instanceof Element)) return false;
  if (t.matches('input, textarea, select, button, a')) return true;
  if (t.isContentEditable) return true;
  if (t.closest('[contenteditable="true"]')) return true;
  return false;
}

// ---- SAFARI GUARD ----

function applySafariSelectionGuard(carousel) {
  const ua = navigator.userAgent;
  const isSafari = /Safari/.test(ua) && !/Chrome|Chromium|Edg|OPR|Android/.test(ua);
  if (!isSafari) return;
  const kill = (e) => {
    if (e.type === 'mousedown' && e.button !== 0) return;
    if (e.target?.closest?.('.alexk-press-link-bar')) return;
    e.preventDefault();
    try { window.getSelection()?.removeAllRanges(); } catch {}
  };
  carousel.addEventListener('selectstart', kill, { passive: false });
  carousel.addEventListener('mousedown',   kill, { passive: false });
  carousel.addEventListener('dragstart',   kill, { passive: false });
}

// ---- UTILITIES ----

function parseSlidesData(carousel) {
  const raw = carousel.getAttribute('data-slides');
  if (!raw) return [];
  try {
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

function shuffleInPlace(array) {
  for (let i = array.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [array[i], array[j]] = [array[j], array[i]];
  }
}

function escAttr(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}
