// The standalone blog's own entry. The sans face (Atkinson Hyperlegible) and the theme store come from app.js; the serif used
// for headlines and the article body is added here, self-hosted from npm like every other font (no CDN).
import '@fontsource-variable/source-serif-4';
import '@fontsource-variable/source-serif-4/wght-italic.css';
import { registerBlogLike } from './blog-like';

// The like button (resources/js/blog-like.js). Registered before Livewire starts its bundled Alpine, like the stores in app.js.
document.addEventListener('alpine:init', () => registerBlogLike(window.Alpine));
