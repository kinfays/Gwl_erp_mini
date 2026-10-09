<div>
    <x-ui.page-header
        :title="$editing ? 'Edit article' : 'New article'"
        :description="$regionName ? 'For the '.$regionName.' blog. Only staff of that region will be able to read it.' : 'Choose the region whose blog this is for.'" />

    <form wire:submit="saveDraft" class="ui-stack" novalidate>
        <x-ui.card title="What is it about?" class="dash-row">
            <div class="ui-form-grid">
                @if ($pickRegion)
                    <x-ui.select label="Region" wire:model.live="regionId" required>
                        <option value="">Select region</option>
                        @foreach ($regions as $region)
                            <option value="{{ $region->id }}">{{ $region->region_name }}</option>
                        @endforeach
                    </x-ui.select>
                @endif

                <x-ui.select label="Kind of article" wire:model="category" required>
                    <option value="">Select</option>
                    @foreach ($categories as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>

                <div class="span-2">
                    <x-ui.input label="Title" wire:model="title" maxlength="180" required />
                </div>
                <div class="span-2">
                    <x-ui.input label="Short summary (optional)" wire:model="summary" maxlength="300" hint="Shown on the blog page under the title. Left empty, the start of the article is used." />
                </div>
            </div>
        </x-ui.card>

        <x-ui.card title="When and where" description="All optional. Leave them empty for an announcement." class="dash-row">
            <div class="ui-form-grid">
                <x-ui.input type="date" label="Date of the activity" wire:model="eventDate" />
                <x-ui.select label="District" wire:model="districtId">
                    <option value="">Whole region / not applicable</option>
                    @foreach ($districts as $district)
                        <option value="{{ $district->id }}">{{ $district->district_name }}</option>
                    @endforeach
                </x-ui.select>
                <div class="span-2">
                    <x-ui.input label="Venue" wire:model="venue" maxlength="160" placeholder="e.g. Regional conference room" />
                </div>
            </div>
        </x-ui.card>

        <x-ui.card title="The article" class="dash-row">
            <div class="ui-form-grid">
                <div class="span-2">
                    <x-ui.textarea label="Article" wire:model="body" rows="14" required
                        hint="Write in plain paragraphs. To format: **bold**, *italic*, a line starting with - for a list, ## for a heading, [link text](https://...) for a link." />
                </div>
                <div class="span-2">
                    <x-ui.input label="Tags (optional)" wire:model="tags" maxlength="200" hint="Up to {{ \App\Models\BlogPost::MAX_TAGS }}, separated by commas, e.g. safety, new-staff, customer-care." />
                </div>
            </div>
        </x-ui.card>

        <x-ui.card title="Cover photo" description="Optional. JPG, PNG or WebP, up to {{ $maxMb }} MB." class="dash-row">
            <div class="ui-stack">
                @if ($cover)
                    <img src="{{ $cover->temporaryUrl() }}" alt="Preview of the new cover photo" style="max-width:360px;width:100%;border-radius:6px;display:block">
                @elseif ($existingCoverUrl && ! $removeCover)
                    <img src="{{ $existingCoverUrl }}" alt="Current cover photo" style="max-width:360px;width:100%;border-radius:6px;display:block">
                @endif

                <x-ui.field label="{{ ($cover || ($existingCoverUrl && ! $removeCover)) ? 'Replace the photo' : 'Choose a photo' }}" for="blog-cover" error="cover">
                    <input id="blog-cover" type="file" class="form-input" wire:model="cover" accept="image/jpeg,image/png,image/webp">
                    <div wire:loading wire:target="cover" class="ui-hint">Uploading…</div>
                </x-ui.field>

                @if ($cover || ($existingCoverUrl && ! $removeCover))
                    <div>
                        <x-ui.button size="sm" variant="ghost" icon="trash-2" wire:click="clearCover">Remove the photo</x-ui.button>
                    </div>
                @endif
            </div>
        </x-ui.card>

        <div class="ui-form-actions">
            <x-ui.button :href="$editing ? route('blog.manage') : route('blog.home')">Cancel</x-ui.button>
            @if ($status === \App\Models\BlogPost::STATUS_PUBLISHED)
                <x-ui.button type="submit" variant="primary" icon="check" loading="saveDraft">Save changes</x-ui.button>
            @else
                <x-ui.button type="submit" icon="file-text" loading="saveDraft">Save draft</x-ui.button>
                <x-ui.button variant="primary" icon="send" wire:click="publish" loading="publish">Publish to the region</x-ui.button>
            @endif
        </div>
    </form>
</div>
