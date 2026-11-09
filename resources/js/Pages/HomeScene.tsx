import '@fontsource-variable/noto-naskh-arabic';
import { icon, pageHref, type Locale } from '../shared';

const features = [
    ['learn', 'أدوات تعليمية', 'Learning tools', 'about'],
    ['library', 'مشاريع إسلامية', 'Islamic projects', 'projects'],
    ['android', 'تطبيقات متميزة', 'Featured apps', 'projects'],
    ['translate', 'متعدد اللغات', 'Multilingual', 'about'],
    ['security', 'مفتوح المصدر', 'Open source', 'github'],
    ['volunteer', 'مجتمع متعاون', 'Community', 'contribute'],
] as const;

export const developmentHref = (locale: Locale) =>
    `${pageHref(locale, 'contribute')}?topic=projects#contribution-form`;

export function HomeScene({locale, title, description}: {locale: Locale; title: string; description: string}) {
    const ar = locale === 'ar';

    return <section className="cinematic" aria-labelledby="home-title">
        <div className="cinematic-copy">
            <p className="scene-kicker">{ar ? 'مبادرة إرثي لتطوير البرمجيات الإسلامية والتعليمية' : 'Erthi initiative for Islamic and educational software'}</p>
            <h1 id="home-title">{title}</h1>
            <p className="scene-description">{description}</p>
            <div className="scene-actions">
                <a className="button primary scene-primary" href={pageHref(locale, 'projects')}>
                    <span>{ar ? 'استكشف مشاريع إرثي' : 'Explore Erthi projects'}</span>
                    <svg className="arrow" viewBox="0 0 24 24" width="23" height="23" fill="none" aria-hidden="true">
                        <path d="M4 12h15m-6-6 6 6-6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                    </svg>
                </a>
                <a className="button outline scene-secondary" href={developmentHref(locale)}>
                    {icon('development', 27)} <span>{ar ? 'طوّر معنا' : 'Develop with us'}</span>
                </a>
            </div>
        </div>
        <div className="cinematic-art">
            <picture>
                <source media="(max-width: 900px)" srcSet="/brand/scene-mobile.webp" />
                <img src="/brand/scene.webp" srcSet="/brand/scene-medium.webp 1152w, /brand/scene.webp 1536w"
                    sizes="100vw" width="1536" height="866" fetchPriority="high"
                    alt={ar ? 'قبة ذهبية ومآذن عند الغروب، وفي المقدمة مصحف مفتوح وشاهد قبر تحيط به الصخور والفوانيس.' : 'Golden dome and minarets at sunset, with an open Qur’an and a gravestone surrounded by rocks and lanterns.'} />
            </picture>
            <figure className="scene-inscription" lang="ar" dir="rtl" aria-label="الرحمن، الآية 26">
                <blockquote>كُلُّ مَنْ <br />عَلَيْهَا فَانٍ</blockquote>
                <figcaption>[الرحمن: 26]</figcaption>
            </figure>
        </div>
        <p className="scene-mobile-verse" lang="ar" dir="rtl" aria-hidden="true">﴿كُلُّ مَنْ عَلَيْهَا فَانٍ﴾ <span>[الرحمن: 26]</span></p>
        <nav className="scene-feature-nav" aria-label={ar ? 'مجالات عمل إرثي' : 'Erthi focus areas'}>
            <ul className="scene-features">
                {features.map(([name, arabic, english, page]) => <li key={name}>
                    <a href={page === 'github' ? 'https://github.com/Erthi-Org' : pageHref(locale, page)}>
                        <span className="scene-feature-icon">{icon(name, 47)}</span>
                        <span>{ar ? arabic : english}</span>
                    </a>
                </li>)}
            </ul>
        </nav>
    </section>;
}
