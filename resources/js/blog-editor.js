// The Regional Blog article editor (TipTap on ProseMirror), mounted by Alpine inside a wire:ignore block and synced to the
// Livewire form. Loaded only on the editor pages.
//
// What it sends is the editor DOCUMENT (JSON). The server never trusts it: BlogContentService cleans it against an allowlist,
// checks every picture id against the region's own pictures, and renders the HTML readers get. Pictures are referenced by id.

import '../css/blog-editor.css';

import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Blockquote from '@tiptap/extension-blockquote';
import Document from '@tiptap/extension-document';
import TextAlign from '@tiptap/extension-text-align';
import BubbleMenu from '@tiptap/extension-bubble-menu';
import { Table, TableRow, TableCell, TableHeader } from '@tiptap/extension-table';
import { CharacterCount, Placeholder } from '@tiptap/extensions';

import { createFigure, createGallery, createVideo, parseVideo } from './blog-editor-nodes';

// A quotation with an optional attribution ("— the Regional Chief Manager"), stored as the blockquote's `cite`.
const Quote = Blockquote.extend({
    addAttributes() {
        return {
            cite: {
                default: null,
                parseHTML: (element) => element.getAttribute('data-cite') || element.querySelector('cite')?.textContent || null,
                renderHTML: (attributes) => (attributes.cite ? { 'data-cite': attributes.cite } : {}),
            },
        };
    },
});

// Pictures, galleries and videos are blocks of the page itself: never inside a list, a quotation or a table cell, because the
// server renders them nowhere else. Everything else is an ordinary block.
const Doc = Document.extend({ content: '(block | blogBlock)+' });

const EMPTY_DOC = { type: 'doc', content: [{ type: 'paragraph' }] };

document.addEventListener('alpine:init', () => {
    window.Alpine.data('blogEditor', (config) => {
        // The editor lives OUTSIDE Alpine's reactive state (a proxied ProseMirror instance misbehaves).
        let editor = null;
        let syncTimer = null;
        let autosaveTimer = null;
        let editVersion = 0;
        let fileInput = null;

        return {
            tick: 0,
            words: 0,
            characters: 0,
            dirty: false,
            busy: false,
            savedLabel: '',
            uploads: [],
            dialog: null,
            previewOpen: false,
            previewHtml: '',
            toolbarOpen: false,

            init() {
                const ctx = {
                    mediaUrl: (id, size) => config.mediaBase.replace('/0/thumb', `/${id}/${size}`),
                    pickFiles: (multiple) => this.pickFiles(multiple),
                    uploadMany: (files) => this.uploadMany(files),
                };

                editor = new Editor({
                    element: this.$refs.surface,
                    content: config.initial.json ?? config.initial.html ?? EMPTY_DOC,
                    extensions: [
                        StarterKit.configure({
                            document: false,
                            heading: { levels: [2, 3, 4] },
                            blockquote: false,
                            codeBlock: false,
                            code: false,
                            link: {
                                openOnClick: false,
                                autolink: true,
                                defaultProtocol: 'https',
                                HTMLAttributes: { rel: 'noopener noreferrer' },
                                isAllowedUri: (url, context) => context.defaultValidate(url) && /^(https:|mailto:|tel:)/i.test(String(url).trim()),
                            },
                        }),
                        Doc,
                        Quote,
                        TextAlign.configure({ types: ['heading', 'paragraph'] }),
                        Table.configure({ resizable: false }),
                        TableRow,
                        TableHeader,
                        TableCell,
                        Placeholder.configure({ placeholder: 'Start writing the article. Use the toolbar to add headings, pictures and galleries.' }),
                        CharacterCount,
                        createFigure(ctx),
                        createGallery(ctx),
                        createVideo(),
                        BubbleMenu.configure({
                            element: this.$refs.bubble,
                            shouldShow: ({ editor: instance, from, to }) => from !== to && instance.isEditable && ! instance.isActive('blogFigure') && ! instance.isActive('blogGallery'),
                        }),
                    ],
                    editorProps: {
                        attributes: { class: 'bm-prose be-prose', role: 'textbox', 'aria-multiline': 'true', 'aria-label': 'Article text' },
                        handlePaste: (view, event) => {
                            const images = this.imagesIn(event.clipboardData?.files);

                            if (images.length === 0) {
                                return false;
                            }

                            event.preventDefault();
                            this.insertImages(images);

                            return true;
                        },
                        handleDrop: (view, event, slice, moved) => {
                            const images = moved ? [] : this.imagesIn(event.dataTransfer?.files);

                            if (images.length === 0) {
                                return false;
                            }

                            event.preventDefault();
                            const at = view.posAtCoords({ left: event.clientX, top: event.clientY });
                            this.insertImages(images, at?.pos);

                            return true;
                        },
                    },
                    onCreate: () => {
                        this.counts();
                        // A Markdown article is converted by loading it here; hand the converted document to the form at once.
                        this.flush();
                    },
                    onUpdate: () => {
                        editVersion++;
                        this.dirty = true;
                        this.counts();
                        this.queueSync();
                    },
                    onTransaction: () => {
                        this.tick++;
                    },
                });

                this.$el.addEventListener('input', (event) => {
                    if (! this.$refs.surface.contains(event.target)) {
                        editVersion++;
                        this.dirty = true;
                    }
                });
                this.$el.addEventListener('change', (event) => {
                    if (! this.$refs.surface.contains(event.target)) {
                        editVersion++;
                        this.dirty = true;
                    }
                });

                const bar = this.$el.querySelector('.be-bar');

                if (bar) {
                    // The sticky toolbar sits under the action bar, which grows when its buttons wrap on a narrow screen.
                    // Set on <html>, not on this element: Livewire re-renders the component's root and would reset it.
                    const measure = () => document.documentElement.style.setProperty('--be-bar-h', `${bar.offsetHeight}px`);

                    measure();
                    window.addEventListener('resize', measure);

                    if ('ResizeObserver' in window) {
                        new ResizeObserver(measure).observe(bar);
                    }
                }

                // Autosave of a DRAFT only; a published article is saved with the button.
                autosaveTimer = window.setInterval(() => this.autosave(), Math.max(3, config.autosaveSeconds) * 1000);

                window.addEventListener('beforeunload', (event) => {
                    if (this.dirty) {
                        event.preventDefault();
                        event.returnValue = '';
                    }
                });
            },

            destroy() {
                window.clearInterval(autosaveTimer);
                editor?.destroy();
            },

            // ------------------------------------------------------------ sync with the form

            counts() {
                this.words = editor.storage.characterCount.words();
                this.characters = editor.storage.characterCount.characters();
            },

            queueSync() {
                window.clearTimeout(syncTimer);
                syncTimer = window.setTimeout(() => this.flush(), 500);
            },

            /** Hand the current document to the Livewire form (no request: it travels with the next action). */
            flush() {
                window.clearTimeout(syncTimer);
                this.$wire.bodyJson = JSON.stringify(editor.getJSON());
            },

            async save(action) {
                this.flush();
                const sent = editVersion;
                const leaving = action === 'publish';

                if (leaving) {
                    this.dirty = false;
                }

                this.busy = true;
                await this.$wire[action]();
                this.busy = false;

                // Still unsaved when the call came back with an error (or a redirect is on its way).
                this.dirty = leaving ? true : editVersion !== sent;
            },

            async autosave() {
                if (! this.dirty || this.busy || this.$wire.status !== 'draft') {
                    return;
                }

                this.flush();
                const sent = editVersion;
                this.busy = true;

                try {
                    await this.$wire.autosave();
                    this.dirty = editVersion !== sent;
                } finally {
                    this.busy = false;
                }
            },

            /** The article exactly as readers see it, rendered on the server by the same partial the article page uses. */
            async openPreview() {
                this.flush();
                this.busy = true;

                try {
                    this.previewHtml = await this.$wire.preview();
                    this.previewOpen = true;
                } finally {
                    this.busy = false;
                }
            },

            saved(detail) {
                this.savedLabel = detail?.label || '';
            },

            /** The server replaced the article (a restored version, or the other person's save): show it. */
            replace(document) {
                try {
                    editor.commands.setContent(typeof document === 'string' ? JSON.parse(document) : document, { emitUpdate: false });
                    this.counts();
                    this.flush();
                    this.dirty = false;
                } catch (error) {
                    this.notify('The article could not be loaded into the editor.', true);
                }
            },

            // ------------------------------------------------------------ toolbar

            /** Re-read on every transaction (this.tick), so buttons show what is active at the cursor. */
            is(name, attrs) {
                this.tick;

                return editor?.isActive(name, attrs) ?? false;
            },

            can(command) {
                this.tick;

                return editor?.can()[command]?.() ?? false;
            },

            run(command, ...args) {
                editor.chain().focus()[command](...args).run();
            },

            heading(value) {
                const chain = editor.chain().focus();
                value === 'p' ? chain.setParagraph().run() : chain.toggleHeading({ level: Number(value) }).run();
            },

            currentBlock() {
                this.tick;

                for (const level of [2, 3, 4]) {
                    if (editor?.isActive('heading', { level })) {
                        return String(level);
                    }
                }

                return 'p';
            },

            align(value) {
                editor.chain().focus().setTextAlign(value).run();
            },

            async link() {
                const previous = editor.getAttributes('link').href || '';
                const value = await this.ask({ title: 'Link', label: 'Address (https://…, mailto: or tel:)', value: previous, allowEmpty: true, placeholder: 'https://' });

                if (value === null) {
                    return;
                }

                if (value.trim() === '') {
                    editor.chain().focus().extendMarkRange('link').unsetLink().run();

                    return;
                }

                if (! /^(https:|mailto:|tel:)/i.test(value.trim())) {
                    this.notify('Links must start with https://, mailto: or tel:.', true);

                    return;
                }

                editor.chain().focus().extendMarkRange('link').setLink({ href: value.trim(), target: '_blank' }).run();
            },

            async attribution() {
                if (! editor.isActive('blockquote')) {
                    editor.chain().focus().toggleBlockquote().run();
                }

                const value = await this.ask({ title: 'Who said it?', label: 'Attribution (optional)', value: editor.getAttributes('blockquote').cite || '', allowEmpty: true, placeholder: 'e.g. The Regional Chief Manager' });

                if (value !== null) {
                    editor.chain().focus().updateAttributes('blockquote', { cite: value.trim() || null }).run();
                }
            },

            insertTable() {
                editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run();
            },

            async video() {
                const value = await this.ask({ title: 'Add a video', label: 'YouTube or Vimeo address', value: '', placeholder: 'https://www.youtube.com/watch?v=…' });

                if (value === null) {
                    return;
                }

                const video = parseVideo(value);

                if (! video) {
                    this.notify('Only YouTube and Vimeo links (https) can be added.', true);

                    return;
                }

                editor.chain().focus().insertContent([{ type: 'blogVideo', attrs: { ...video, caption: '' } }, { type: 'paragraph' }]).run();
            },

            async addImage() {
                this.insertImages(await this.pickFiles(true));
            },

            async addGallery() {
                const files = await this.pickFiles(true);
                const media = await this.uploadMany(files);

                if (media.length > 0) {
                    editor.chain().focus().insertContent([{ type: 'blogGallery', attrs: { layout: 'grid', items: media.map((item) => ({ mediaId: item.id, alt: '', caption: '' })) } }, { type: 'paragraph' }]).run();
                }
            },

            tableActive() {
                this.tick;

                return editor?.isActive('table') ?? false;
            },

            // ------------------------------------------------------------ pictures

            imagesIn(files) {
                return Array.from(files || []).filter((file) => /^image\/(jpeg|png|webp|gif)$/.test(file.type));
            },

            pickFiles(multiple = true) {
                return new Promise((resolve) => {
                    fileInput?.remove();
                    fileInput = Object.assign(document.createElement('input'), { type: 'file', accept: 'image/jpeg,image/png,image/webp,image/gif', multiple, hidden: true });
                    fileInput.addEventListener('change', () => resolve(this.imagesIn(fileInput.files)));
                    fileInput.addEventListener('cancel', () => resolve([]));
                    document.body.append(fileInput);
                    fileInput.click();
                });
            },

            async insertImages(files, position) {
                const media = await this.uploadMany(files);
                const content = media.map((item) => ({ type: 'blogFigure', attrs: { mediaId: item.id, alt: '', caption: '', credit: '', align: 'center', size: 'inline' } }));

                if (content.length === 0) {
                    return;
                }

                // A picture is followed by an empty paragraph: the cursor lands there, and the next insert cannot replace the picture.
                content.push({ type: 'paragraph' });

                const chain = editor.chain().focus();
                typeof position === 'number' ? chain.insertContentAt(position, content).run() : chain.insertContent(content).run();

                // One picture: ask for its description now, because a picture without one is invisible to some readers.
                if (media.length === 1) {
                    const alt = await this.ask({ title: 'Describe the picture', label: 'What is in it? (read aloud to people who cannot see it; leave empty if it is only decoration)', value: '', allowEmpty: true, placeholder: 'e.g. Staff practising leak detection on a test pipe' });

                    if (alt) {
                        this.setAlt(media[0].id, alt.trim());
                    }
                }
            },

            setAlt(mediaId, alt) {
                editor.state.doc.descendants((node, pos) => {
                    if (node.type.name === 'blogFigure' && node.attrs.mediaId === mediaId) {
                        editor.view.dispatch(editor.view.state.tr.setNodeMarkup(pos, undefined, { ...node.attrs, alt }));

                        return false;
                    }

                    return true;
                });
            },

            async uploadMany(files) {
                const done = [];

                for (let index = 0; index < files.length; index += 3) {
                    const results = await Promise.all(files.slice(index, index + 3).map((file) => this.upload(file)));
                    done.push(...results.filter(Boolean));
                }

                return done;
            },

            /** One picture to the upload endpoint, with progress. Resolves to the media row or null (the error is shown). */
            upload(file) {
                const entry = { key: `${Date.now()}-${Math.random()}`, name: file.name, pct: 0, error: null };
                this.uploads.push(entry);

                return new Promise((resolve) => {
                    const body = new FormData();
                    body.append('file', file);
                    body.append('kind', 'inline');

                    if (this.$wire.postId) {
                        body.append('post_id', this.$wire.postId);
                    }

                    if (this.$wire.regionId) {
                        body.append('region_id', this.$wire.regionId);
                    }

                    const request = new XMLHttpRequest();
                    request.open('POST', config.uploadUrl);
                    request.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name="csrf-token"]')?.content || '');
                    request.setRequestHeader('Accept', 'application/json');
                    request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                    request.upload.addEventListener('progress', (event) => {
                        if (event.lengthComputable) {
                            entry.pct = Math.round((event.loaded / event.total) * 100);
                        }
                    });

                    const fail = (message) => {
                        entry.error = message;
                        window.setTimeout(() => this.removeUpload(entry), 6000);
                        resolve(null);
                    };

                    request.addEventListener('load', () => {
                        let payload = {};

                        try {
                            payload = JSON.parse(request.responseText);
                        } catch (error) {
                            // Not JSON: handled below by the status.
                        }

                        if (request.status === 201) {
                            entry.pct = 100;
                            window.setTimeout(() => this.removeUpload(entry), 800);
                            resolve(payload);
                        } else if (request.status === 422) {
                            fail(payload.errors?.file?.[0] || payload.message || 'That picture was refused.');
                        } else if (request.status === 419) {
                            fail('Your session has expired. Reload the page and sign in again.');
                        } else if (request.status === 413) {
                            fail('The picture is too large.');
                        } else {
                            fail('The picture could not be uploaded. Try again.');
                        }
                    });
                    request.addEventListener('error', () => fail('The connection was lost. Try again.'));
                    request.send(body);
                });
            },

            removeUpload(entry) {
                this.uploads = this.uploads.filter((item) => item.key !== entry.key);
            },

            notify(message, error = false) {
                const entry = { key: `${Date.now()}-${Math.random()}`, name: message, pct: 100, error: error ? message : null, note: ! error };
                this.uploads.push(entry);
                window.setTimeout(() => this.removeUpload(entry), 5000);
            },

            // ------------------------------------------------------------ a small prompt dialog

            ask(options) {
                return new Promise((resolve) => {
                    this.dialog = { ...options, value: options.value ?? '', resolve };
                    this.$nextTick(() => this.$refs.dialogInput?.focus());
                });
            },

            answer(submitted) {
                const dialog = this.dialog;
                this.dialog = null;
                dialog?.resolve(submitted ? dialog.value : null);
            },
        };
    });
});
