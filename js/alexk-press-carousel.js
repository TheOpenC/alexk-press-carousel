if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', onDomReady);
} else {
  onDomReady();
}

function onDomReady() {
  const carousel = findCarousel();
  if (!carousel) return;

  const imgElement = findCarouselImage(carousel);
  if (!imgElement) return;

  const linkBar = carousel.querySelector('.alexk-press-link-bar');
  const linkEl  = carousel.querySelector('.alexk-press-link');

  const images = parseImagesData(carousel);
  if (images.length === 0) return;

  const state = createCarouselState(images);

  // Show first image + set initial link
  showNextAndPreload(carousel, imgElement, linkEl, state);

  // Clicking the IMAGE or anywhere on the carousel advances to next
  // Clicking the LINK does NOT advance (it opens the article)
  carousel.addEventListener('click', function(event) {
    // If click landed on the link bar or the link itself, let it through — don't advance
    if (linkBar && linkBar.contains(event.target)) return;
    onCarouselClick(event);
  });

  carousel._alexkPressCarousel = { imgElement, linkEl, state };

  installKeyboardNavigation(carousel);
}

function onCarouselClick(event) {
  const carousel = event.currentTarget;
  const store = carousel._alexkPressCarousel;
  if (!store) return;
  showNextAndPreload(carousel, store.imgElement, store.linkEl, store.state);
}

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
      showNextAndPreload(carouselEl, store.imgElement, store.linkEl, store.state);
    }
    // Forward-only by design — no backwards nav
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

function createCarouselState(images) {
  return {
    allImages: images.slice(),
    deck: [],
    lastShown: null,
  };
}

function getNextImage(state) {
  if (!state) return null;
  ensureDeckReady(state);
  const next = state.deck.pop();
  if (next && next.fallback) state.lastShown = next.fallback;
  return next;
}

function showNextAndPreload(carouselEl, imgElement, linkEl, state) {
  const current = getNextImage(state);
  if (!current) return;

  updateCarouselImage(imgElement, current);
  updatePressLink(linkEl, current);

  const next = peekNextImage(state);
  if (next) preloadImage(carouselEl, next);
}

function peekNextImage(state) {
  if (!state) return null;
  ensureDeckReady(state);
  if (!state.deck || state.deck.length === 0) return null;
  return state.deck[state.deck.length - 1];
}

function ensureDeckReady(state) {
  if (!state.deck || state.deck.length === 0) {
    let tries = 0;
    do {
      state.deck = state.allImages.slice();
      shuffleInPlace(state.deck);
      tries++;
      if (tries > 10) break;
    } while (
      state.lastShown &&
      state.deck.length > 1 &&
      state.deck[state.deck.length - 1].fallback === state.lastShown
    );
  }
}

function preloadImage(carouselEl, imageObj) {
  const existing = document.getElementById('alexk-press-preload-next');
  if (existing) existing.remove();

  const link = document.createElement('link');
  link.id  = 'alexk-press-preload-next';
  link.rel = 'preload';
  link.as  = 'image';
  link.href = imageObj.fallback;

  const sizes = imageObj.sizes ||
    (carouselEl?.querySelector('img')?.sizes) ||
    '100vw';

  if (imageObj.webp_srcset) {
    link.setAttribute('imagesrcset', imageObj.webp_srcset);
    link.setAttribute('imagesizes', sizes);
    link.type = 'image/webp';
  } else if (imageObj.jpg_srcset) {
    link.setAttribute('imagesrcset', imageObj.jpg_srcset);
    link.setAttribute('imagesizes', sizes);
    link.type = 'image/jpeg';
  }

  document.head.appendChild(link);
}

function findCarousel() {
  return document.querySelector('.alexk-press-carousel[data-images]');
}

function findCarouselImage(carousel) {
  return carousel.querySelector('img');
}

function parseImagesData(carousel) {
  const raw = carousel.getAttribute('data-images');
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

function updateCarouselImage(imgElement, imageObj) {
  if (!imgElement || !imageObj) return;

  const pictureEl = imgElement.closest('picture');
  if (!pictureEl) return;

  const webpSource = pictureEl.querySelector('source[type="image/webp"]');
  const jpegSource = pictureEl.querySelector('source[type="image/jpeg"]');

  if (webpSource && imageObj.webp_srcset) {
    webpSource.srcset = imageObj.webp_srcset;
    webpSource.sizes  = imageObj.sizes || '100vw';
  }
  if (jpegSource && imageObj.jpg_srcset) {
    jpegSource.srcset = imageObj.jpg_srcset;
    jpegSource.sizes  = imageObj.sizes || '100vw';
  }

  imgElement.src   = imageObj.fallback || imgElement.src;
  imgElement.sizes = imageObj.sizes || '100vw';
  if (typeof imageObj.alt === 'string') imgElement.alt = imageObj.alt;
}

/**
 * Update the "Read article →" link below the image.
 * If the current item has no press_url, hide the bar entirely.
 */
function updatePressLink(linkEl, imageObj) {
  if (!linkEl) return;
  const bar = linkEl.closest('.alexk-press-link-bar');

  if (imageObj.press_url) {
    linkEl.href = imageObj.press_url;
    if (bar) bar.style.visibility = 'visible';
  } else {
    linkEl.href = '#';
    if (bar) bar.style.visibility = 'hidden';
  }
}

// Safari ghost-selection guard (press carousel only)
(function () {
  const carousel = document.querySelector('.alexk-press-carousel');
  if (!carousel) return;

  const ua = navigator.userAgent;
  const isSafari = /Safari/.test(ua) && !/Chrome|Chromium|Edg|OPR|Android/.test(ua);
  if (!isSafari) return;

  const killSelection = (e) => {
    if (e.type === 'mousedown' && e.button !== 0) return;
    // Don't kill clicks on the link bar
    if (e.target && e.target.closest && e.target.closest('.alexk-press-link-bar')) return;
    e.preventDefault();
    try { window.getSelection()?.removeAllRanges(); } catch {}
  };

  carousel.addEventListener('selectstart', killSelection, { passive: false });
  carousel.addEventListener('mousedown',   killSelection, { passive: false });
  carousel.addEventListener('dragstart',   killSelection, { passive: false });

  carousel.querySelectorAll('img').forEach((img) => {
    img.setAttribute('draggable', 'false');
  });
})();
