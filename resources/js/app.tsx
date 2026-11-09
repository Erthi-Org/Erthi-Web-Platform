import '@fontsource-variable/noto-sans-arabic';
import '@fontsource-variable/noto-kufi-arabic';
import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
const pages = {
    Public: () => import('./Pages/Public').then(module => module.default),
    Admin: () => import('./Pages/Admin').then(module => module.default),
    Login: () => import('./Pages/Login').then(module => module.default),
};
void createInertiaApp({
    resolve: name => pages[name as keyof typeof pages](),
    setup: ({ el, App, props }) => createRoot(el).render(<App {...props} />),
    progress: { color: '#e4b979', includeCSS: false },
});
