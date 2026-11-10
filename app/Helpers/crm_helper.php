<?php

declare(strict_types=1);

/** Original geometric line icons; no external icon font or asset dependency. */
function crm_icon(string $name, string $class = ''): string
{
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v4m10-4v4M3 11h18m-13 4h2m4 0h2"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M17 5a3 3 0 0 1 0 6m2 4a5 5 0 0 1 3 5"/>',
        'folder' => '<path d="M3 7a2 2 0 0 1 2-2h5l2 3h7a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'check' => '<rect x="3" y="3" width="18" height="18" rx="4"/><path d="m7 12 3 3 7-7"/>',
        'layers' => '<path d="m12 3 10 5-10 5L2 8zM2 13l10 5 10-5M2 18l10 5 10-5"/>',
        'repeat' => '<path d="M4 8h15l-4-4m5 12H5l4 4M4 8v5m16 3v-5"/>',
        'cart' => '<path d="M2 3h3l3 13h11l3-9H6"/><circle cx="10" cy="21" r="1"/><circle cx="18" cy="21" r="1"/>',
        'file' => '<path d="M14 3H5v18h14V8zM14 3v6h5M8 13h8m-8 4h6"/>',
        'pen' => '<path d="m4 16 12-12 4 4L8 20H4zM13 7l4 4"/>',
        'wallet' => '<rect x="3" y="5" width="18" height="15" rx="3"/><path d="M3 8h18m-6 4h6v5h-6z"/>',
        'chart' => '<path d="M4 3v18h18M8 16v-4m5 4V7m5 9v-6"/>',
        'note' => '<path d="M4 3h16v13l-5 5H4zM15 21v-5h5M8 8h8m-8 4h6"/>',
        'message' => '<path d="M21 14a4 4 0 0 1-4 4H8l-5 4V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4zM7 8h10M7 12h7"/>',
        'ticket' => '<path d="M3 5h18v5a2 2 0 0 0 0 4v5H3v-5a2 2 0 0 0 0-4zM15 5v3m0 3v2m0 3v3"/>',
        'book' => '<path d="M12 6C8 3 5 3 2 4v16c4-1 7 0 10 2 3-2 6-3 10-2V4c-3-1-6-1-10 2zm0 0v16"/>',
        'settings' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v3m0 14v3M2 12h3m14 0h3M5 5l2 2m10 10 2 2M5 19l2-2M17 7l2-2"/>',
        'search' => '<circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/>',
        'bell' => '<path d="M5 17h14l-2-4V9a5 5 0 0 0-10 0v4zm5 4h4"/>',
        'chevron' => '<path d="m9 5 7 7-7 7"/>',
        'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'close' => '<path d="m5 5 14 14M5 19 19 5"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/>',
        'arrow' => '<path d="M4 12h16m-6-6 6 6-6 6"/>',
        'logout' => '<path d="M10 3H4v18h6m4-14 5 5-5 5M8 12h12"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v1"/>',
    ];
    return '<svg class="crm-icon '.esc($class).'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$name] ?? $paths['dashboard']).'</svg>';
}
