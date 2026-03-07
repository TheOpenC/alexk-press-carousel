# AlexK Press Carousel

A WordPress plugin for displaying press coverage as a forward-only, randomly shuffled image carousel.

## Features

- **Bulk flagging** — Add/remove press items from the Media Library grid or list view
- **Green dot indicator** — Visual badge in the grid showing which items are in the press carousel
- **Press URL field** — Each item can have an associated article link, shown below the image
- **Text-optimised image processing** — Lossless WebP + high-quality JPEG (95, 4:4:4 chroma) for crisp text/screenshots
- **Responsive images** — Generates derivatives at 320, 480, 768, 1024, 1400px widths
- **Forward-only navigation** — Click the image (or press ArrowRight/Space) to advance; no back button
- **Random shuffle** — Fisher-Yates shuffle with no-repeat-last-shown logic
- **Image preloading** — Next image is preloaded in the background
- **Progress HUD** — Admin overlay showing processing progress during bulk operations
- **Safari fix** — Ghost-selection / paint bug prevention

## Usage

### Shortcode

```
[alexk_press]
```

Place this shortcode on any page to render the press carousel.

### Adding press items

1. Go to **Media Library**
2. Open any image (screenshot, scan, PDF screencap converted to image)
3. Check **Include in press carousel**
4. Add the **Press article URL** (shown as "Read article →" below the image)
5. Save — responsive derivatives are generated automatically

### Bulk operations

In the Media Library grid view, enter Bulk Select mode to use **Add to press** / **Remove from press** buttons.

In List view, use the **Bulk Actions** dropdown.

## Image format notes

For best text legibility, upload images at the highest resolution available. The plugin will:
- Generate lossless WebP (preferred by modern browsers)
- Generate JPEG at quality 95 with 4:4:4 chroma subsampling (no colour degradation on text edges)
- Use Imagick if available, falling back to WP's GD editor

## Requirements

- WordPress 6.0+
- PHP 8.0+
- Imagick extension recommended (GD fallback available)
