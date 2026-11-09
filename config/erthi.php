<?php

return [
    'admin_prefix' => preg_match('/\Amanage-[a-z0-9]{16,48}\z/', (string) env('ERTHI_ADMIN_PREFIX')) ? env('ERTHI_ADMIN_PREFIX') : 'admin',
    'indexing' => (bool) env('ERTHI_INDEXING', false),
    'locales' => ['ar', 'en'],
    'pages' => ['home', 'about', 'dream', 'projects', 'contribute', 'contact', 'privacy'],
    'topics' => ['quran', 'content', 'language', 'security', 'projects'],
];
