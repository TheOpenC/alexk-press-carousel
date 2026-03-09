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
    allSlides: slides.slice(),
    deck: [],
    lastShown: null,
  };

  const linkBar = carousel.querySelector('.alexk-press-link-bar');
  const linkEl  = carousel.querySelector('.alexk-press-link');

  // Apply orientation to PHP-rendered first slide immediately
  applyOrientationAttributes(carousel.querySelector('.alexk-press-slide'));
  updatePressLink(linkEl, slides[0].press_url);

  // Exclude the PHP-rendered first slide from the deck so the first click
  // always shows something new. If there's only 1 slide, keep full list.
  const firstKey = JSON.stringify(slides[0].images.map(i => i.fallback));
  const remaining = slides.filter(s => JSON.stringify(s.images.map(i => i.fallback)) !== firstKey);
  state.allSlides = remaining.length > 0 ? remaining : slides.slice();

  // Preloading disabled — preload <link> doesn't match what <picture> actually loads
  // (srcset picks webp, preload hint was for jpg fallback → browser warning + wasted req)

  // Click anywhere on carousel (except link bar) → advance
  carousel.addEventListener('click', function(event) {
    if (linkBar && linkBar.contains(event.target)) return;
    advanceSlide(carousel, state);
  });

  carousel._alexkPressCarousel = { state, linkEl };
  installKeyboardNavigation(carousel);

  // Safari ghost-selection guard
  applySafariSelectionGuard(carousel);
}

// ---- SLIDE NAVIGATION ----

function advanceSlide(carousel, state) {
  const slide = getNextSlide(state);
  if (!slide) return;
  renderSlide(carousel, slide);
}

function renderSlide(carousel, slide) {
  const slideEl = carousel.querySelector('.alexk-press-slide');
  if (!slideEl) return;

  slideEl.innerHTML = slide.images.map(img => `
    <picture class="alexk-press-picture">
      ${img.webp_srcset ? `<source type="image/webp" srcset="${escAttr(img.webp_srcset)}" sizes="(min-resolution: 2dppx) 2800px, (max-width: 1400px) 100vw, 1400px">` : ''}
      ${img.jpg_srcset  ? `<source type="image/jpeg" srcset="${escAttr(img.jpg_srcset)}" sizes="(min-resolution: 2dppx) 2800px, (max-width: 1400px) 100vw, 1400px">` : ''}
      <img class="alexk-press-image"
           src="${escAttr(img.fallback)}"
           alt="${escAttr(img.alt || '')}"
           sizes="(min-resolution: 2dppx) 2800px, (max-width: 1400px) 100vw, 1400px"
           loading="eager"
           decoding="async">
    </picture>
  `).join('');

  applyOrientationAttributes(slideEl);
  updatePressLink(carousel.querySelector('.alexk-press-link'), slide.press_url);
}

// ---- ORIENTATION ----

function applyOrientationAttributes(slideEl) {
  if (!slideEl) return;
  slideEl.querySelectorAll('.alexk-press-image').forEach(img => {
    const fallbackSrc = img.getAttribute('src'); // always the reliable JPEG fallback

    const tryFallback = () => {
      // Image loaded (complete=true) but is broken (0×0 decode) — CDN served bad bytes
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
      if (picture) picture.dataset.orientation = img.naturalWidth >= img.naturalHeight ? 'landscape' : 'portrait';
    };

    // onerror: network fail or completely invalid response
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

function preloadSlide(slide) {
  const existing = document.getElementById('alexk-press-preload-next');
  if (existing) existing.remove();
  const img = slide.images[0];
  if (!img || !img.fallback) return;
  // Preload the fallback JPG — universally supported, no srcset complexity
  const link = document.createElement('link');
  link.id   = 'alexk-press-preload-next';
  link.rel  = 'preload';
  link.as   = 'image';
  link.href = img.fallback;
  document.head.appendChild(link);
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
      advanceSlide(carouselEl, store.state);
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
