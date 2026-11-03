{{--
    Full-colour Excel / PDF file icons for export buttons (unlike x-ui.icon, which is a one-colour outline).
        <x-ui.file-icon type="excel" />   <x-ui.file-icon type="pdf" />
    Decorative: the link or button around it carries the accessible name.
--}}
@props(['type' => 'excel'])

@if ($type === 'pdf')
    <svg {{ $attributes->class(['ui-file-icon']) }} viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
        <path d="M9 2.5h10.5L26 9v18.5a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2v-23a2 2 0 0 1 2-2z" fill="#ffffff" stroke="#e5252a" stroke-width="2" stroke-linejoin="round"/>
        <path d="M19.5 2.5V9H26" fill="none" stroke="#e5252a" stroke-width="2" stroke-linejoin="round"/>
        <path d="M16.5 8.5c1.6 3.2.6 6.2-2.8 9.2 3-1.1 6-1.1 8.3.6-3-.6-5.2.8-6.9 3.2" fill="none" stroke="#e5252a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
        <text x="16" y="27.2" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="6.6" font-weight="700" fill="#3b3b3b">PDF</text>
    </svg>
@else
    <svg {{ $attributes->class(['ui-file-icon']) }} viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
        <path d="M9 5.5A2.5 2.5 0 0 1 11.5 3H26.5A2.5 2.5 0 0 1 29 5.5V9.5H9z" fill="#33c481"/>
        <rect x="9" y="9.5" width="20" height="6.5" fill="#21a366"/>
        <rect x="9" y="16" width="20" height="6.5" fill="#107c41"/>
        <path d="M9 22.5H29V26.5A2.5 2.5 0 0 1 26.5 29H11.5A2.5 2.5 0 0 1 9 26.5z" fill="#185c37"/>
        <rect x="2" y="8" width="16.5" height="16.5" rx="2.5" fill="#107c41"/>
        <path d="M6.6 12.6l7.3 7.3M13.9 12.6l-7.3 7.3" stroke="#ffffff" stroke-width="2.4" stroke-linecap="round"/>
    </svg>
@endif
