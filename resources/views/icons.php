<?php
// Local inline icons shared by page templates.
return static function(string $name):string {
 $paths=[
 'home'=>'<path d="m3 10 9-7 9 7v10H3z"/><path d="M9 20v-7h6v7"/>',
 'user'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
 'users'=>'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/><circle cx="9" cy="7" r="4"/>',
 'grid'=>'<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
 'shield'=>'<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6z"/><path d="m8 12 3 3 5-6"/>',
 'key'=>'<circle cx="8" cy="15" r="5"/><path d="m12 11 9-9M17 6l3 3M15 8l3 3"/>',
 'mail'=>'<rect x="3" y="5" width="18" height="14" rx="3"/><path d="m3 6 9 7 9-7"/>',
 'qr'=>'<path d="M9 3H3v6M15 3h6v6M3 15v6h6M21 15v6h-6"/><path d="M7 7h2v2H7zM15 7h2v2h-2zM7 15h2v2H7zM14 14h3v3h-3zM18 18h1"/>',
 'code'=>'<path d="m8 5-7 7 7 7M16 5l7 7-7 7M14 3l-4 18"/>',
 'settings'=>'<path d="M4 7h16M4 17h16"/><circle cx="9" cy="7" r="3"/><circle cx="15" cy="17" r="3"/>',
 'download'=>'<path d="M12 3v12m-5-5 5 5 5-5M4 17v4h16v-4"/>',
 'arrow'=>'<path d="M4 12h16m-6-6 6 6-6 6"/>',
 'external'=>'<path d="M14 3h7v7m0-7-11 11M10 3H3v18h18v-7"/>',
 'logout'=>'<path d="M9 3H3v18h6m5-14 5 5-5 5M8 12h13"/>',
 'check'=>'<path d="m5 12 4 4L19 6"/>',
 'search'=>'<circle cx="10.5" cy="10.5" r="7.5"/><path d="m16 16 5 5"/>',
 ];
 return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$name]??$paths['grid']).'</svg>';
};
