// The like button of the Regional Blog (docs/14-module-regional-blog.md, "Likes and views").
//
// One small Alpine component per heart, no page reload and nothing else re-rendered. The server decides everything: this only asks
// for the state the reader wants (POST = like, DELETE = unlike; both idempotent) and shows the state and count it answers.
//   * Optimistic: the heart and the number change at once, and are put back if the request fails.
//   * Never a double count: while a request is in flight further clicks are ignored (the button is aria-busy), and the server
//     counts a person's like once whatever arrives.
//   * Every heart of the same article on the page (top and bottom of an article) is kept in step through one window event.
//   * A real <button>: Enter and Space work, the pressed state is aria-pressed, the name carries the count.
//
// Markup it reads (blog/partials/like.blade.php): data-id, data-liked ("1" / "0"), data-count, data-like-url on the root element.

const EVENT = 'blog-like-changed';

function plural(count, one, many) {
    return count === 1 ? one : many;
}

export function registerBlogLike(Alpine) {
    Alpine.data('blogLike', () => ({
        id: 0,
        url: '',
        liked: false,
        count: 0,
        busy: false,

        init() {
            const data = this.$el.dataset;

            this.id = Number(data.id);
            this.url = data.likeUrl;
            this.liked = data.liked === '1';
            this.count = Number(data.count);
        },

        label() {
            return `Like this article, ${this.count} ${plural(this.count, 'like', 'likes')}`;
        },

        // "You and 12 others" for the person who liked it; the plain count (or an invitation) otherwise.
        summary() {
            if (this.liked) {
                const others = this.count - 1;

                return others <= 0 ? 'You like this' : `You and ${others} ${plural(others, 'other', 'others')}`;
            }

            return this.count === 0 ? 'Be the first to like this' : `${this.count} ${plural(this.count, 'like', 'likes')}`;
        },

        sync(detail) {
            if (detail && Number(detail.id) === this.id) {
                this.liked = Boolean(detail.liked);
                this.count = Number(detail.count);
            }
        },

        announce() {
            window.dispatchEvent(new CustomEvent(EVENT, { detail: { id: this.id, liked: this.liked, count: this.count } }));
        },

        async toggle() {
            if (this.busy) {
                return;
            }

            const wasLiked = this.liked;
            const wasCount = this.count;

            this.busy = true;
            this.liked = ! wasLiked;
            this.count = Math.max(0, wasCount + (wasLiked ? -1 : 1));
            this.announce();

            try {
                const response = await fetch(this.url, {
                    method: wasLiked ? 'DELETE' : 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                });

                if (! response.ok) {
                    throw new Error(`Like request failed (${response.status})`);
                }

                const state = await response.json();

                this.liked = Boolean(state.liked);
                this.count = Number(state.count);
            } catch (error) {
                this.liked = wasLiked;
                this.count = wasCount;
            } finally {
                this.busy = false;
                this.announce();
            }
        },
    }));
}
