// SignaturePad for the visitor kiosk, bundled and version-locked by package-lock.json instead of
// fetched from a CDN. It loads as a deferred module, so it has run before DOMContentLoaded.
import SignaturePad from 'signature_pad';

window.SignaturePad = SignaturePad;
