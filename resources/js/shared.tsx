import { useEffect, type ReactNode } from 'react';

export type Locale = 'ar' | 'en';
export type Copy = Record<string, {title: string; body: string}>;

export const icon = (name: string, size = 28) => (
    <img src={`/icons/${name}.svg`} width={size} height={size} alt="" aria-hidden="true" className="icon" />
);

export const pageHref = (locale: Locale, page = 'home') =>
    `/${locale}${page === 'home' ? '' : `/${page}`}`;

export function useDirection(locale: Locale) {
    useEffect(() => {
        document.documentElement.lang = locale;
        document.documentElement.dir = locale === 'ar' ? 'rtl' : 'ltr';
    }, [locale]);
}

export function Brand({ locale, logo, footer = false }: {locale: Locale; logo?: string | null; footer?: boolean}) {
    return <a className="brand" href={pageHref(locale)} aria-label={locale === 'ar' ? 'إرثي، الرئيسية' : 'Erthi home'}>
        <img width="48" height="48" alt="" src={logo ?? `/brand/seal-${footer ? 'footer' : 'header'}.webp`} />
        <span><strong>إرثي</strong><small lang="en">ERTHI</small></span>
    </a>;
}

export function Field({ label, error, children }: {label: string; error?: string; children: ReactNode}) {
    return <label className="field"><span>{label}</span>{children}{error && <small className="error" role="alert">{error}</small>}</label>;
}

export function Notice({ children }: {children?: string | null}) {
    return children ? <p className="notice" role="status">{children}</p> : null;
}
