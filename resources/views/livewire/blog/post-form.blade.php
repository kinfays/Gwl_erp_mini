<div
    class="be-root"
    x-data="blogEditor(@js($editorConfig))"
    x-on:blog-saved.window="saved($event.detail)"
    x-on:blog-document-replaced.window="replace($event.detail.document)"
>
    {{-- ------------------------------------------------------------ action bar --}}
    <div class="be-bar">
        <a class="b-btn b-btn-ghost" href="{{ $editing ? route('blog.manage') : route('blog.home') }}">← {{ __('Articles') }}</a>
        <h1>{{ $editing ? __('Edit article') : __('New article') }}@if ($regionName) <span class="be-hint">· {{ $regionName }}</span>@endif</h1>

        <span class="be-state" x-bind:class="{ 'is-dirty': dirty }" role="status" aria-live="polite"
            x-text="busy ? '{{ __('Saving…') }}' : (dirty ? '{{ __('Unsaved changes') }}' : (savedLabel || '{{ __('No changes yet') }}'))"></span>

        <div class="be-actions">
            <button type="button" class="b-btn" x-on:click="openPreview()">{{ __('Preview as readers see it') }}</button>
            @if ($status === \App\Models\BlogPost::STATUS_PUBLISHED)
                <button type="button" class="b-btn b-btn-primary" x-on:click="save('saveDraft')" x-bind:disabled="busy">{{ __('Save changes') }}</button>
            @else
                <button type="button" class="b-btn" x-on:click="save('saveDraft')" x-bind:disabled="busy">{{ __('Save draft') }}</button>
                <button type="button" class="b-btn b-btn-primary" x-on:click="save('publish')" x-bind:disabled="busy">{{ __('Publish to the region') }}</button>
            @endif
        </div>
    </div>

    @if ($conflict)
        <div class="be-conflict" role="alert">
            <strong>{{ __('This article was saved by someone else after you opened it.') }}</strong>
            {{ $conflict['by'] ? __('Last saved by :name', ['name' => $conflict['by']]) : '' }}{{ $conflict['at'] ? ' · '.$conflict['at'] : '' }}.
            {{ __('Nothing of yours has been lost on this screen, and nothing has been overwritten.') }}
            <div class="be-row">
                <button type="button" class="b-btn" wire:click="reloadTheirs" wire:confirm="{{ __('Load their version? What you typed since you opened the article will be replaced on screen.') }}">{{ __('Load their version') }}</button>
                <button type="button" class="b-btn b-btn-primary" wire:click="saveOver" wire:confirm="{{ __('Save your version over theirs? Theirs stays in the list of saved versions.') }}">{{ __('Save mine over theirs') }}</button>
            </div>
        </div>
    @endif

    @if ($notice)
        <div class="b-notice" role="status">{{ $notice }}</div>
    @endif

    @if ($errors->any())
        <div class="b-notice b-notice-warn" role="alert">
            <strong>{{ __('Please check:') }}</strong>
            <ul style="margin:.3rem 0 0;padding-left:1.2rem">
                @foreach (array_unique($errors->all()) as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="be-shell">
        {{-- ------------------------------------------------------------ the article --}}
        <div>
            <label class="b-sr" for="be-title">{{ __('Headline') }}</label>
            <input id="be-title" class="be-title" type="text" wire:model="title" maxlength="180" placeholder="{{ __('Headline') }}" autocomplete="off">

            <label class="b-sr" for="be-summary">{{ __('Standfirst') }}</label>
            <textarea id="be-summary" class="be-summary" wire:model="summary" rows="2" maxlength="300" placeholder="{{ __('Standfirst: one or two sentences under the headline (optional)') }}"></textarea>

            {{-- toolbar --}}
            <div class="be-toolbar" role="toolbar" aria-label="{{ __('Formatting') }}" aria-controls="be-surface">
                <div class="be-tools">
                    <button type="button" class="be-tb" aria-label="{{ __('Undo') }}" title="{{ __('Undo (Ctrl+Z)') }}" x-bind:disabled="! can('undo')" x-on:click="run('undo')">↶</button>
                    <button type="button" class="be-tb" aria-label="{{ __('Redo') }}" title="{{ __('Redo (Ctrl+Shift+Z)') }}" x-bind:disabled="! can('redo')" x-on:click="run('redo')">↷</button>
                    <span class="be-sep" aria-hidden="true"></span>

                    <label class="b-sr" for="be-block">{{ __('Text style') }}</label>
                    <select id="be-block" class="be-select" x-bind:value="currentBlock()" x-on:change="heading($event.target.value)">
                        <option value="p">{{ __('Paragraph') }}</option>
                        <option value="2">{{ __('Heading') }}</option>
                        <option value="3">{{ __('Subheading') }}</option>
                        <option value="4">{{ __('Small heading') }}</option>
                    </select>
                    <span class="be-sep" aria-hidden="true"></span>

                    <button type="button" class="be-tb" aria-label="{{ __('Bold') }}" title="{{ __('Bold (Ctrl+B)') }}" x-bind:aria-pressed="is('bold').toString()" x-on:click="run('toggleBold')"><strong>B</strong></button>
                    <button type="button" class="be-tb" aria-label="{{ __('Italic') }}" title="{{ __('Italic (Ctrl+I)') }}" x-bind:aria-pressed="is('italic').toString()" x-on:click="run('toggleItalic')"><em>I</em></button>
                    <button type="button" class="be-tb" aria-label="{{ __('Underline') }}" title="{{ __('Underline (Ctrl+U)') }}" x-bind:aria-pressed="is('underline').toString()" x-on:click="run('toggleUnderline')"><u>U</u></button>
                    <button type="button" class="be-tb" aria-label="{{ __('Strikethrough') }}" title="{{ __('Strikethrough') }}" x-bind:aria-pressed="is('strike').toString()" x-on:click="run('toggleStrike')"><s>S</s></button>
                    <button type="button" class="be-tb" aria-label="{{ __('Link') }}" title="{{ __('Link (Ctrl+K)') }}" x-bind:aria-pressed="is('link').toString()" x-on:click="link()">{{ __('Link') }}</button>
                    <span class="be-sep" aria-hidden="true"></span>

                    <button type="button" class="be-tb" aria-label="{{ __('Bulleted list') }}" title="{{ __('Bulleted list') }}" x-bind:aria-pressed="is('bulletList').toString()" x-on:click="run('toggleBulletList')">• {{ __('List') }}</button>
                    <button type="button" class="be-tb" aria-label="{{ __('Numbered list') }}" title="{{ __('Numbered list') }}" x-bind:aria-pressed="is('orderedList').toString()" x-on:click="run('toggleOrderedList')">1. {{ __('List') }}</button>
                    <button type="button" class="be-tb" aria-label="{{ __('Quotation') }}" title="{{ __('Quotation') }}" x-bind:aria-pressed="is('blockquote').toString()" x-on:click="run('toggleBlockquote')">❝</button>
                    <button type="button" class="be-tb" aria-label="{{ __('Say who is quoted') }}" title="{{ __('Quotation with who said it') }}" x-on:click="attribution()">❝ {{ __('by…') }}</button>
                    <span class="be-sep" aria-hidden="true"></span>

                    <button type="button" class="be-tb" aria-label="{{ __('Align left') }}" title="{{ __('Align left') }}" x-bind:aria-pressed="(! is({ textAlign: 'center' }) && ! is({ textAlign: 'right' })).toString()" x-on:click="align('left')">⇤</button>
                    <button type="button" class="be-tb" aria-label="{{ __('Centre') }}" title="{{ __('Centre') }}" x-bind:aria-pressed="is({ textAlign: 'center' }).toString()" x-on:click="align('center')">↔</button>
                    <button type="button" class="be-tb" aria-label="{{ __('Align right') }}" title="{{ __('Align right') }}" x-bind:aria-pressed="is({ textAlign: 'right' }).toString()" x-on:click="align('right')">⇥</button>
                    <span class="be-sep" aria-hidden="true"></span>

                    <button type="button" class="be-tb" aria-label="{{ __('Divider') }}" title="{{ __('Horizontal rule') }}" x-on:click="run('setHorizontalRule')">―</button>
                    <button type="button" class="be-tb" aria-label="{{ __('Table') }}" title="{{ __('Insert a table') }}" x-on:click="insertTable()">▦ {{ __('Table') }}</button>
                    <span class="be-sep" aria-hidden="true"></span>

                    <button type="button" class="be-tb" aria-label="{{ __('Add pictures') }}" title="{{ __('Add a picture (you can also paste or drop one into the text)') }}" x-on:click="addImage()">🖼 {{ __('Picture') }}</button>
                    <button type="button" class="be-tb" aria-label="{{ __('Add a gallery') }}" title="{{ __('Several pictures in one block') }}" x-on:click="addGallery()">▤ {{ __('Gallery') }}</button>
                    <button type="button" class="be-tb" aria-label="{{ __('Add a video') }}" title="{{ __('YouTube or Vimeo') }}" x-on:click="video()">▶ {{ __('Video') }}</button>
                </div>

                <div class="be-tablebar" x-show="tableActive()" x-cloak role="group" aria-label="{{ __('Table') }}">
                    <button type="button" class="be-mini" x-on:click="run('addRowBefore')">{{ __('Row above') }}</button>
                    <button type="button" class="be-mini" x-on:click="run('addRowAfter')">{{ __('Row below') }}</button>
                    <button type="button" class="be-mini" x-on:click="run('addColumnBefore')">{{ __('Column left') }}</button>
                    <button type="button" class="be-mini" x-on:click="run('addColumnAfter')">{{ __('Column right') }}</button>
                    <button type="button" class="be-mini" x-on:click="run('toggleHeaderRow')">{{ __('Header row') }}</button>
                    <button type="button" class="be-mini" x-on:click="run('mergeOrSplit')">{{ __('Merge / split cells') }}</button>
                    <button type="button" class="be-mini be-danger" x-on:click="run('deleteRow')">{{ __('Delete row') }}</button>
                    <button type="button" class="be-mini be-danger" x-on:click="run('deleteColumn')">{{ __('Delete column') }}</button>
                    <button type="button" class="be-mini be-danger" x-on:click="run('deleteTable')">{{ __('Delete table') }}</button>
                </div>
            </div>

            {{-- The editing surface: TipTap owns it, Livewire must not touch it. --}}
            <div class="be-surface" id="be-surface" wire:ignore>
                <div x-ref="surface"></div>

                <div x-ref="bubble" class="be-bubble" role="toolbar" aria-label="{{ __('Format the selected text') }}">
                    <button type="button" class="be-tb" aria-label="{{ __('Bold') }}" x-bind:aria-pressed="is('bold').toString()" x-on:click="run('toggleBold')"><strong>B</strong></button>
                    <button type="button" class="be-tb" aria-label="{{ __('Italic') }}" x-bind:aria-pressed="is('italic').toString()" x-on:click="run('toggleItalic')"><em>I</em></button>
                    <button type="button" class="be-tb" aria-label="{{ __('Underline') }}" x-bind:aria-pressed="is('underline').toString()" x-on:click="run('toggleUnderline')"><u>U</u></button>
                    <button type="button" class="be-tb" aria-label="{{ __('Link') }}" x-bind:aria-pressed="is('link').toString()" x-on:click="link()">{{ __('Link') }}</button>
                    <button type="button" class="be-tb" aria-label="{{ __('Heading') }}" x-bind:aria-pressed="is('heading', { level: 2 }).toString()" x-on:click="heading('2')">H2</button>
                    <button type="button" class="be-tb" aria-label="{{ __('Subheading') }}" x-bind:aria-pressed="is('heading', { level: 3 }).toString()" x-on:click="heading('3')">H3</button>
                    <button type="button" class="be-tb" aria-label="{{ __('Quotation') }}" x-bind:aria-pressed="is('blockquote').toString()" x-on:click="run('toggleBlockquote')">❝</button>
                </div>
            </div>

            <div class="be-status">
                <span><span x-text="words">0</span> {{ __('words') }} · <span x-text="characters">0</span> {{ __('characters') }}</span>
                <span>{{ __('Pictures: paste, drop or use the Picture button. Drag the handle of a block to move it.') }}</span>
            </div>
        </div>

        {{-- ------------------------------------------------------------ the article's details --}}
        <aside aria-label="{{ __('Article details') }}">
            <section class="be-card">
                <h2>{{ __('About the article') }}</h2>

                @if ($pickRegion)
                    <label class="b-field">{{ __('Region') }}
                        <select class="b-select" wire:model.live="regionId" required>
                            <option value="">{{ __('Select region') }}</option>
                            @foreach ($regions as $region)
                                <option value="{{ $region->id }}">{{ $region->region_name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif

                <label class="b-field">{{ __('Kind of article') }}
                    <select class="b-select" wire:model="category" required>
                        <option value="">{{ __('Select') }}</option>
                        @foreach ($categories as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="b-field">{{ __('District') }}
                    <select class="b-select" wire:model="districtId">
                        <option value="">{{ __('Whole region / not applicable') }}</option>
                        @foreach ($districts as $district)
                            <option value="{{ $district->id }}">{{ $district->district_name }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="b-field">{{ __('Date of the activity') }}
                    <input class="b-input" type="date" wire:model="eventDate">
                    <span class="be-hint">{{ __('A date in the future shows the article under "Upcoming events".') }}</span>
                </label>

                <label class="b-field">{{ __('Venue') }}
                    <input class="b-input" type="text" wire:model="venue" maxlength="160" placeholder="{{ __('e.g. Regional conference room') }}">
                </label>

                <label class="b-field">{{ __('Tags') }}
                    <input class="b-input" type="text" wire:model="tags" maxlength="200">
                    <span class="be-hint">{{ __('Up to :n, separated by commas, e.g. safety, customer-care.', ['n' => \App\Models\BlogPost::MAX_TAGS]) }}</span>
                </label>
            </section>

            <section class="be-card">
                <h2>{{ __('Cover photo') }}</h2>

                @if ($cover || ($existingCoverUrl && ! $removeCover))
                    <div class="be-focal" aria-hidden="true">
                        <img src="{{ $cover ? $cover->temporaryUrl() : $existingCoverUrl }}" alt="" style="object-position: {{ $coverFocalX }}% {{ $coverFocalY }}%">
                        <span class="be-focal-dot" style="left: {{ $coverFocalX }}%; top: {{ $coverFocalY }}%"></span>
                    </div>

                    <label class="b-field" style="margin-top:.8rem">{{ __('Focus: left ↔ right') }}
                        <input class="be-range" type="range" min="0" max="100" wire:model.live.debounce.200ms="coverFocalX">
                    </label>
                    <label class="b-field">{{ __('Focus: top ↕ bottom') }}
                        <input class="be-range" type="range" min="0" max="100" wire:model.live.debounce.200ms="coverFocalY">
                        <span class="be-hint">{{ __('Cards and the front page crop the picture; the focus keeps the important part in view.') }}</span>
                    </label>

                    <label class="b-field">{{ __('Caption') }}
                        <input class="b-input" type="text" wire:model="coverCaption" maxlength="240">
                    </label>
                    <label class="b-field">{{ __('Credit') }}
                        <input class="b-input" type="text" wire:model="coverCredit" maxlength="120" placeholder="{{ __('Photo: …') }}">
                    </label>
                @endif

                <label class="b-field">{{ ($cover || ($existingCoverUrl && ! $removeCover)) ? __('Replace the photo') : __('Choose a photo') }}
                    <input id="blog-cover" type="file" class="b-input" wire:model="cover" accept="image/jpeg,image/png,image/webp">
                    <span class="be-hint">{{ __('JPG, PNG or WebP, up to :mb MB. It is re-saved without location data.', ['mb' => $maxMb]) }}</span>
                </label>
                <div wire:loading wire:target="cover" class="be-hint">{{ __('Uploading…') }}</div>

                @if ($cover || ($existingCoverUrl && ! $removeCover))
                    <button type="button" class="b-btn" wire:click="clearCover">{{ __('Remove the photo') }}</button>
                @endif
            </section>

            @if ($editing)
                <section class="be-card">
                    <h2>{{ __('Saved versions') }}</h2>
                    @if ($revisions->isEmpty())
                        <p class="be-hint">{{ __('Versions appear here each time you save.') }}</p>
                    @else
                        <ul class="be-versions">
                            @foreach ($revisions as $revision)
                                <li wire:key="rev-{{ $revision->id }}">
                                    <span>
                                        {{ $revision->created_at?->format('j M, H:i') }}
                                        <small>{{ $revision->user?->full_name ?? __('Someone') }} · {{ match ($revision->reason) { 'autosave' => __('automatic'), 'restore' => __('restored'), default => __('saved') } }}</small>
                                    </span>
                                    <button type="button" class="b-btn" wire:click="restoreRevision({{ $revision->id }})"
                                        wire:confirm="{{ __('Restore this version? What is on screen now stays in the list of versions.') }}">{{ __('Restore') }}</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif
        </aside>
    </div>

    {{-- ------------------------------------------------------------ preview, prompts, upload progress --}}
    <div class="be-preview" x-show="previewOpen" x-cloak x-trap.noscroll="previewOpen" x-on:keydown.escape.window="previewOpen = false" role="dialog" aria-modal="true" aria-label="{{ __('Preview') }}">
        <div class="be-preview-bar">
            <span>{{ __('Preview: this is how readers will see the article') }}</span>
            <button type="button" class="b-btn" x-on:click="previewOpen = false">{{ __('Close preview') }}</button>
        </div>
        <article class="b-article" x-html="previewHtml"></article>
    </div>

    {{-- Rendered only while a question is open: the form binds to the question, which does not exist otherwise. --}}
    <template x-if="dialog">
        <div class="be-scrim" x-on:keydown.escape.window="answer(false)">
            <form class="be-dialog" role="dialog" aria-modal="true" aria-labelledby="be-dialog-title" x-trap.noscroll="true" x-on:submit.prevent="answer(true)">
                <h2 id="be-dialog-title" x-text="dialog.title"></h2>
                <label class="b-field"><span x-text="dialog.label"></span>
                    <input class="b-input" type="text" x-ref="dialogInput" x-model="dialog.value" x-bind:placeholder="dialog.placeholder || ''">
                </label>
                <div class="be-row">
                    <button type="button" class="b-btn" x-on:click="answer(false)">{{ __('Cancel') }}</button>
                    <button type="submit" class="b-btn b-btn-primary">{{ __('OK') }}</button>
                </div>
            </form>
        </div>
    </template>

    <div class="be-toasts" aria-live="polite">
        <template x-for="item in uploads" x-bind:key="item.key">
            <div class="be-toast" x-bind:class="{ 'is-error': item.error }">
                <span x-text="item.error ? item.error : item.name"></span>
                <div class="be-progress" x-show="! item.error && ! item.note"><span x-bind:style="`width:${item.pct}%`"></span></div>
            </div>
        </template>
    </div>
</div>
