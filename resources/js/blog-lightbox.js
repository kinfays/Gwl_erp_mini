// Lightbox for the Regional Blog: opens the pictures of an article, a gallery block or a photo-gallery album.
// Loaded only on the article and gallery pages. No dependency: keyboard (arrows, Home/End, Esc, Tab kept inside),
// swipe on touch screens, caption and "3 / 12" counter, focus returned to the picture that opened it.
//
// Markup it reads (written by BlogContentService): <a data-lightbox href="large picture" data-w data-h><img alt></a>, with an
// optional <figcaption> in the same <figure>. The group is the closest [data-lightbox-group], else the closest [data-gallery],
// else the whole article body, else the page.

const LINK = 'a[data-lightbox]';
const GROUP = '[data-lightbox-group], [data-gallery], .bm-prose';
const SWIPE_MIN = 48;

let overlay = null;
let items = [];
let index = 0;
let opener = null;
let touchStart = null;

function el(tag, className, attrs = {}) {
    const node = document.createElement(tag);

    if (className) {
        node.className = className;
    }

    Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, value));

    return node;
}

function build() {
    overlay = el('div', 'bl', { role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Picture viewer', hidden: '' });

    const top = el('div', 'bl-top');
    const counter = el('span', 'bl-count', { 'aria-live': 'polite' });
    const close = el('button', 'bl-close', { type: 'button', 'aria-label': 'Close the viewer' });
    close.textContent = '×';
    top.append(counter, close);

    const stage = el('div', 'bl-stage');
    const prev = el('button', 'bl-prev', { type: 'button', 'aria-label': 'Previous picture' });
    prev.textContent = '‹';
    const next = el('button', 'bl-next', { type: 'button', 'aria-label': 'Next picture' });
    next.textContent = '›';
    const image = el('img', '', { alt: '', draggable: 'false' });
    stage.append(prev, image, next);

    const caption = el('div', 'bl-cap', { 'aria-live': 'polite' });

    overlay.append(top, stage, caption);
    document.body.append(overlay);

    close.addEventListener('click', hide);
    prev.addEventListener('click', () => show(index - 1));
    next.addEventListener('click', () => show(index + 1));

    // A click on the dark area (not the picture or a button) closes it.
    stage.addEventListener('click', (event) => {
        if (event.target === stage) {
            hide();
        }
    });

    overlay.addEventListener('touchstart', (event) => {
        const touch = event.changedTouches[0];
        touchStart = { x: touch.clientX, y: touch.clientY };
    }, { passive: true });

    overlay.addEventListener('touchend', (event) => {
        if (! touchStart) {
            return;
        }

        const touch = event.changedTouches[0];
        const dx = touch.clientX - touchStart.x;
        const dy = touch.clientY - touchStart.y;
        touchStart = null;

        if (Math.abs(dx) >= SWIPE_MIN && Math.abs(dx) > Math.abs(dy) * 1.5) {
            show(index + (dx < 0 ? 1 : -1));
        }
    }, { passive: true });
}

function captionOf(link) {
    const figure = link.closest('figure');
    const text = figure?.querySelector('figcaption')?.textContent?.trim();

    return text || link.dataset.caption || link.querySelector('img')?.getAttribute('alt') || '';
}

function collect(link) {
    const group = link.closest(GROUP) || document;

    return Array.from(group.querySelectorAll(LINK)).map((anchor) => ({
        href: anchor.getAttribute('href'),
        alt: anchor.querySelector('img')?.getAttribute('alt') || '',
        caption: captionOf(anchor),
        node: anchor,
    }));
}

function show(target) {
    if (items.length === 0) {
        return;
    }

    index = (target + items.length) % items.length;

    const item = items[index];
    const image = overlay.querySelector('.bl-stage img');
    const many = items.length > 1;

    image.src = item.href;
    image.alt = item.alt;
    overlay.querySelector('.bl-count').textContent = many ? `${index + 1} / ${items.length}` : '';
    overlay.querySelector('.bl-cap').textContent = item.caption;
    overlay.querySelector('.bl-prev').hidden = ! many;
    overlay.querySelector('.bl-next').hidden = ! many;

    // Warm the neighbours so the next swipe is instant.
    [index + 1, index - 1].forEach((neighbour) => {
        if (many) {
            new Image().src = items[(neighbour + items.length) % items.length].href;
        }
    });
}

function open(link) {
    if (! overlay) {
        build();
    }

    items = collect(link);
    opener = link;

    const start = Math.max(0, items.findIndex((item) => item.node === link));

    overlay.hidden = false;
    document.documentElement.classList.add('bl-open');
    show(start);
    overlay.querySelector('.bl-close').focus();
}

function hide() {
    if (! overlay || overlay.hidden) {
        return;
    }

    overlay.hidden = true;
    document.documentElement.classList.remove('bl-open');
    overlay.querySelector('.bl-stage img').removeAttribute('src');
    opener?.focus();
    opener = null;
}

function trapTab(event) {
    const focusable = Array.from(overlay.querySelectorAll('button')).filter((button) => ! button.hidden);

    if (focusable.length === 0) {
        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (! event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    } else if (! overlay.contains(document.activeElement)) {
        event.preventDefault();
        first.focus();
    }
}

document.addEventListener('click', (event) => {
    const link = event.target instanceof Element ? event.target.closest(LINK) : null;

    // Let "open in a new tab" gestures work as usual.
    if (! link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
    }

    event.preventDefault();
    open(link);
});

document.addEventListener('keydown', (event) => {
    if (! overlay || overlay.hidden) {
        return;
    }

    switch (event.key) {
        case 'Escape':
            event.preventDefault();
            hide();
            break;
        case 'ArrowRight':
            event.preventDefault();
            show(index + 1);
            break;
        case 'ArrowLeft':
            event.preventDefault();
            show(index - 1);
            break;
        case 'Home':
            event.preventDefault();
            show(0);
            break;
        case 'End':
            event.preventDefault();
            show(items.length - 1);
            break;
        case 'Tab':
            trapTab(event);
            break;
    }
});
