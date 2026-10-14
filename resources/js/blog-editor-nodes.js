// The Regional Blog editor's own blocks: a picture with caption (blogFigure), a gallery (blogGallery) and an embedded video
// (blogVideo). They hold only a media id (or a video provider + id) and text: never a picture address. The server
// (BlogContentService) re-checks every id and rebuilds the HTML, so what is built here is only the editing view.

import { Node, mergeAttributes } from '@tiptap/core';

export function el(tag, props = {}, children = []) {
    const node = document.createElement(tag);

    Object.entries(props).forEach(([key, value]) => {
        if (value === null || value === undefined || value === false) {
            return;
        }

        if (key === 'class') {
            node.className = value;
        } else if (key === 'text') {
            node.textContent = value;
        } else if (key.startsWith('on')) {
            node.addEventListener(key.slice(2), value);
        } else {
            node.setAttribute(key, value === true ? '' : value);
        }
    });

    [].concat(children).filter(Boolean).forEach((child) => node.append(child));

    return node;
}

function button(label, onclick, { title, pressed, className = 'be-mini' } = {}) {
    return el('button', { type: 'button', class: className, text: label, title: title || label, 'aria-label': title || label, 'aria-pressed': pressed === undefined ? null : String(pressed), onclick });
}

function field(label, value, onchange, { placeholder = '', maxlength = 300 } = {}) {
    const input = el('input', { type: 'text', class: 'be-input', value, placeholder, maxlength, 'aria-label': label });
    input.addEventListener('change', () => onchange(input.value));
    input.addEventListener('keydown', (event) => event.stopPropagation());

    return el('label', { class: 'be-field' }, [el('span', { text: label }), input]);
}

/** Replace the attributes of the node at `getPos()` (the way every control of a block edits it). */
function patch(editor, getPos, node, changes) {
    const pos = getPos();

    if (typeof pos !== 'number') {
        return;
    }

    editor.view.dispatch(editor.view.state.tr.setNodeMarkup(pos, undefined, { ...node.attrs, ...changes }));
}

const SIZES = [['inline', 'Column'], ['wide', 'Wide'], ['full', 'Full width']];
const ALIGNS = [['left', 'Left'], ['center', 'Centre'], ['right', 'Right']];

export function createFigure(ctx) {
    return Node.create({
        name: 'blogFigure',
        group: 'blogBlock',
        atom: true,
        draggable: true,
        selectable: true,

        addAttributes() {
            const attr = (name, fallback) => ({
                default: fallback,
                parseHTML: (element) => element.getAttribute(`data-${name}`) ?? fallback,
                renderHTML: (attributes) => ({ [`data-${name}`]: attributes[name] ?? fallback }),
            });

            return { mediaId: attr('media-id', null), alt: attr('alt', ''), caption: attr('caption', ''), credit: attr('credit', ''), align: attr('align', 'center'), size: attr('size', 'inline') };
        },

        parseHTML() {
            return [{ tag: 'figure[data-blog-figure]' }];
        },

        renderHTML({ HTMLAttributes }) {
            return ['figure', mergeAttributes({ 'data-blog-figure': '' }, HTMLAttributes)];
        },

        addNodeView() {
            return ({ node, getPos, editor }) => {
                let current = node;
                const dom = el('figure', { class: 'be-figure', 'data-blog-figure': '' });

                const render = () => {
                    const a = current.attrs;
                    dom.className = `be-figure bm-align-${a.align} bm-size-${a.size}`;
                    dom.replaceChildren();

                    const img = el('img', { src: ctx.mediaUrl(a.mediaId, 'medium'), alt: a.alt || '', draggable: 'false' });
                    const warning = a.alt ? null : el('p', { class: 'be-warn', text: 'Add a description of the picture for people who cannot see it (or leave it empty if it is only decoration).' });

                    const controls = el('div', { class: 'be-figcontrols' }, [
                        el('div', { class: 'be-group', role: 'group', 'aria-label': 'Picture width' }, SIZES.map(([value, label]) => button(label, () => patch(editor, getPos, current, { size: value }), { pressed: a.size === value }))),
                        el('div', { class: 'be-group', role: 'group', 'aria-label': 'Picture position' }, ALIGNS.map(([value, label]) => button(label, () => patch(editor, getPos, current, { align: value }), { pressed: a.align === value }))),
                        field('Description (alt text)', a.alt, (value) => patch(editor, getPos, current, { alt: value }), { placeholder: 'What is in the picture?' }),
                        field('Caption', a.caption, (value) => patch(editor, getPos, current, { caption: value }), { placeholder: 'Shown under the picture' }),
                        field('Credit', a.credit, (value) => patch(editor, getPos, current, { credit: value }), { placeholder: 'Photo: …', maxlength: 120 }),
                        button('Remove picture', () => {
                            const pos = getPos();
                            editor.view.dispatch(editor.view.state.tr.delete(pos, pos + current.nodeSize));
                        }, { className: 'be-mini be-danger' }),
                    ]);

                    dom.append(el('div', { class: 'be-figimg' }, [img]), warning, controls);
                };

                render();

                return {
                    dom,
                    update(updated) {
                        if (updated.type !== current.type) {
                            return false;
                        }

                        const focused = dom.contains(document.activeElement) && document.activeElement.tagName === 'INPUT';

                        // Do not rebuild while someone is typing in one of the boxes; the value is saved on change.
                        if (! focused || updated.attrs.mediaId !== current.attrs.mediaId) {
                            current = updated;
                            render();
                        } else {
                            current = updated;
                        }

                        return true;
                    },
                    selectNode: () => dom.classList.add('is-selected'),
                    deselectNode: () => dom.classList.remove('is-selected'),
                    stopEvent: (event) => dom.querySelector('.be-figcontrols')?.contains(event.target) ?? false,
                    ignoreMutation: () => true,
                };
            };
        },
    });
}

const LAYOUTS = [['grid', 'Grid'], ['mosaic', 'Mosaic'], ['carousel', 'Carousel']];

export function createGallery(ctx) {
    return Node.create({
        name: 'blogGallery',
        group: 'blogBlock',
        atom: true,
        draggable: true,
        selectable: true,

        addAttributes() {
            return {
                layout: { default: 'grid', parseHTML: (element) => element.getAttribute('data-layout') || 'grid', renderHTML: (a) => ({ 'data-layout': a.layout }) },
                items: {
                    default: [],
                    parseHTML: (element) => {
                        try {
                            return JSON.parse(element.getAttribute('data-items') || '[]');
                        } catch (error) {
                            return [];
                        }
                    },
                    renderHTML: (a) => ({ 'data-items': JSON.stringify(a.items || []) }),
                },
            };
        },

        parseHTML() {
            return [{ tag: 'div[data-blog-gallery]' }];
        },

        renderHTML({ HTMLAttributes }) {
            return ['div', mergeAttributes({ 'data-blog-gallery': '' }, HTMLAttributes)];
        },

        addNodeView() {
            return ({ node, getPos, editor }) => {
                let current = node;
                let dragFrom = null;
                const dom = el('div', { class: 'be-gallery', 'data-blog-gallery': '' });

                const setItems = (items) => patch(editor, getPos, current, { items });
                const move = (from, to) => {
                    const items = [...current.attrs.items];

                    if (to < 0 || to >= items.length || from === to) {
                        return;
                    }

                    items.splice(to, 0, items.splice(from, 1)[0]);
                    setItems(items);
                };

                const render = () => {
                    const { layout, items } = current.attrs;
                    dom.replaceChildren();

                    const header = el('div', { class: 'be-figcontrols' }, [
                        el('strong', { class: 'be-label', text: `Gallery · ${items.length} picture${items.length === 1 ? '' : 's'}` }),
                        el('div', { class: 'be-group', role: 'group', 'aria-label': 'Gallery layout' }, LAYOUTS.map(([value, label]) => button(label, () => patch(editor, getPos, current, { layout: value }), { pressed: layout === value }))),
                        button('Add pictures', async () => {
                            const files = await ctx.pickFiles(true);
                            const added = (await ctx.uploadMany(files)).map((media) => ({ mediaId: media.id, alt: '', caption: '' }));
                            setItems([...current.attrs.items, ...added].slice(0, 40));
                        }),
                        button('Remove gallery', () => {
                            const pos = getPos();
                            editor.view.dispatch(editor.view.state.tr.delete(pos, pos + current.nodeSize));
                        }, { className: 'be-mini be-danger' }),
                    ]);

                    const grid = el('ol', { class: `be-galgrid be-gal-${layout}`, 'aria-label': 'Pictures in this gallery, in order' });

                    items.forEach((item, index) => {
                        const li = el('li', { class: 'be-galitem', draggable: 'true' });

                        li.addEventListener('dragstart', (event) => {
                            dragFrom = index;
                            event.dataTransfer.effectAllowed = 'move';
                            event.dataTransfer.setData('text/plain', `gallery-${index}`);
                        });
                        li.addEventListener('dragover', (event) => {
                            if (dragFrom !== null) {
                                event.preventDefault();
                                li.classList.add('is-over');
                            }
                        });
                        li.addEventListener('dragleave', () => li.classList.remove('is-over'));
                        li.addEventListener('drop', (event) => {
                            if (dragFrom !== null) {
                                event.preventDefault();
                                event.stopPropagation();
                                const from = dragFrom;
                                dragFrom = null;
                                move(from, index);
                            }
                        });
                        li.addEventListener('dragend', () => {
                            dragFrom = null;
                            li.classList.remove('is-over');
                        });

                        const update = (changes) => setItems(items.map((other, i) => (i === index ? { ...other, ...changes } : other)));

                        li.append(
                            el('img', { src: ctx.mediaUrl(item.mediaId, 'thumb'), alt: item.alt || '', draggable: 'false' }),
                            el('div', { class: 'be-galtools' }, [
                                button('←', () => move(index, index - 1), { title: `Move picture ${index + 1} earlier` }),
                                button('→', () => move(index, index + 1), { title: `Move picture ${index + 1} later` }),
                                button('✕', () => setItems(items.filter((_, i) => i !== index)), { title: `Remove picture ${index + 1}`, className: 'be-mini be-danger' }),
                            ]),
                            field('Description', item.alt || '', (value) => update({ alt: value }), { placeholder: 'Alt text' }),
                            field('Caption', item.caption || '', (value) => update({ caption: value }), { placeholder: 'Caption' }),
                        );

                        grid.append(li);
                    });

                    dom.append(header, grid);
                };

                render();

                return {
                    dom,
                    update(updated) {
                        if (updated.type !== current.type) {
                            return false;
                        }

                        const typing = dom.contains(document.activeElement) && document.activeElement.tagName === 'INPUT';
                        current = updated;

                        if (! typing) {
                            render();
                        }

                        return true;
                    },
                    selectNode: () => dom.classList.add('is-selected'),
                    deselectNode: () => dom.classList.remove('is-selected'),
                    stopEvent: (event) => dom.contains(event.target) && event.target !== dom,
                    ignoreMutation: () => true,
                };
            };
        },
    });
}

/** YouTube and Vimeo by id, as the server accepts them (it re-parses the address and builds the embed itself). */
export function parseVideo(url) {
    let parsed;

    try {
        parsed = new URL(String(url).trim());
    } catch (error) {
        return null;
    }

    if (parsed.protocol !== 'https:') {
        return null;
    }

    const host = parsed.hostname.replace(/^(www\.|m\.)/, '');
    let match;

    if (host === 'youtu.be') {
        match = parsed.pathname.slice(1).match(/^[A-Za-z0-9_-]{11}$/);

        return match ? { provider: 'youtube', videoId: match[0] } : null;
    }

    if (host === 'youtube.com' || host === 'youtube-nocookie.com') {
        match = parsed.pathname.match(/^\/(?:embed|shorts|live)\/([A-Za-z0-9_-]{11})/);
        const id = match ? match[1] : (parsed.pathname === '/watch' ? parsed.searchParams.get('v') : null);

        return id && /^[A-Za-z0-9_-]{11}$/.test(id) ? { provider: 'youtube', videoId: id } : null;
    }

    if (host === 'vimeo.com' || host === 'player.vimeo.com') {
        match = parsed.pathname.match(/^\/(?:video\/)?(\d{5,12})/);

        return match ? { provider: 'vimeo', videoId: match[1] } : null;
    }

    return null;
}

export function createVideo() {
    return Node.create({
        name: 'blogVideo',
        group: 'blogBlock',
        atom: true,
        draggable: true,
        selectable: true,

        addAttributes() {
            const attr = (name, fallback) => ({
                default: fallback,
                parseHTML: (element) => element.getAttribute(`data-${name}`) ?? fallback,
                renderHTML: (attributes) => ({ [`data-${name}`]: attributes[name] ?? fallback }),
            });

            return { provider: attr('provider', 'youtube'), videoId: attr('video-id', ''), caption: attr('caption', '') };
        },

        parseHTML() {
            return [{ tag: 'figure[data-blog-video]' }];
        },

        renderHTML({ HTMLAttributes }) {
            return ['figure', mergeAttributes({ 'data-blog-video': '' }, HTMLAttributes)];
        },

        addNodeView() {
            return ({ node, getPos, editor }) => {
                let current = node;
                const dom = el('figure', { class: 'be-video', 'data-blog-video': '' });

                const render = () => {
                    const a = current.attrs;
                    dom.replaceChildren(
                        el('p', { class: 'be-videobox', text: `${a.provider === 'vimeo' ? 'Vimeo' : 'YouTube'} video · ${a.videoId}` }),
                        el('div', { class: 'be-figcontrols' }, [
                            field('Caption', a.caption, (value) => patch(editor, getPos, current, { caption: value }), { placeholder: 'Shown under the video' }),
                            button('Remove video', () => {
                                const pos = getPos();
                                editor.view.dispatch(editor.view.state.tr.delete(pos, pos + current.nodeSize));
                            }, { className: 'be-mini be-danger' }),
                        ]),
                    );
                };

                render();

                return {
                    dom,
                    update(updated) {
                        if (updated.type !== current.type) {
                            return false;
                        }

                        current = updated;

                        if (! (dom.contains(document.activeElement) && document.activeElement.tagName === 'INPUT')) {
                            render();
                        }

                        return true;
                    },
                    selectNode: () => dom.classList.add('is-selected'),
                    deselectNode: () => dom.classList.remove('is-selected'),
                    stopEvent: (event) => dom.contains(event.target) && event.target !== dom,
                    ignoreMutation: () => true,
                };
            };
        },
    });
}
