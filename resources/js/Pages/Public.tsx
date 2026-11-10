import { Head, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { developmentHref, HomeScene } from './HomeScene';
import { Brand, Field, Notice, icon, pageHref, useDirection, type Copy, type Locale } from '../shared';

type Project = {title: string; summary: string; repository: string; icon: string};
type Channels = {email: string; website: string | null; facebook: string | null; youtube: string | null; x: string | null};
type PublicProps = {locale: Locale; locales: Locale[]; page: string; content: Copy; projects: Project[]; logo: string | null; channels: Channels; seo: {title: string}; notice?: string; developmentTopic?: boolean};
const nav = ['home', 'about', 'dream', 'projects', 'contribute', 'contact'];

function Arrow() {
    return <svg className="arrow" viewBox="0 0 24 24" width="18" height="18" fill="none" aria-hidden="true">
        <path d="M5 12h13m-5-5 5 5-5 5" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
    </svg>;
}

function Header({ locale, locales, page, content, logo }: PublicProps) {
    const [open, setOpen] = useState(false);
    const ar = locale === 'ar';
    const alternate = ar ? 'en' : 'ar';

    return <header className={page === 'home' ? 'site-header cinematic-header' : 'site-header'}>
        <div className="shell header-row">
            <Brand locale={locale} logo={logo} />
            <button className="menu-toggle" type="button" aria-expanded={open} aria-controls="main-nav" onClick={() => setOpen(!open)}>
                <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="1.6">
                    <path d="M3 6h18M3 12h18M3 18h18"/>
                </svg>
                <span>{ar ? 'القائمة' : 'Menu'}</span>
            </button>
            <nav id="main-nav" className={open ? 'main-nav is-open' : 'main-nav'} aria-label={ar ? 'التنقل الرئيسي' : 'Main navigation'}>
                {nav.map(key => <a key={key} href={pageHref(locale, key)} aria-current={page === key ? 'page' : undefined}>
                    {key === 'home' ? (ar ? 'الرئيسية' : 'Home') : key === 'contribute' ? (ar ? 'شاركنا اقتراحك' : 'Get involved') : content[key].title}
                </a>)}
            </nav>
            {locales.includes(alternate) && <a className="language" href={pageHref(alternate, page)} hrefLang={alternate} lang={alternate}>
                {icon('language', 19)} {ar ? 'English' : 'العربية'}
            </a>}
        </div>
    </header>;
}

function Footer({ locale, content, logo, channels }: PublicProps) {
    const ar = locale === 'ar';
    return <footer className="site-footer">
        <div className="shell footer-main">
            <div><Brand locale={locale} logo={logo} footer /><p>{ar ? 'مبادرة لمشاريع إسلامية وتعليمية مفتوحة المصدر.' : 'An initiative for open-source Islamic and educational projects.'}</p></div>
            <div className="footer-links">
                <a href={pageHref(locale, 'privacy')}>{content.privacy.title}</a>
                <a href={pageHref(locale, 'contact')}>{content.contact.title}</a>
                <a href="https://github.com/Erthi-Org">{icon('github', 17)} GitHub</a>
                <a href={`mailto:${channels.email}`}>{icon('mail', 20)} <bdi>{channels.email}</bdi></a>
                {channels.facebook && <a href={channels.facebook}>Facebook</a>}
                {channels.youtube && <a href={channels.youtube}>YouTube</a>}
                {channels.x && <a href={channels.x}>X</a>}
            </div>
        </div>
        <div className="shell footer-base"><span>© {new Date().getFullYear()} {ar ? 'إرثي' : 'Erthi'}</span><bdi>{channels.website?.toUpperCase()}</bdi></div>
    </footer>;
}

function Projects({ projects, locale }: {projects: Project[]; locale: Locale}) {
    const ar = locale === 'ar';
    return <div className="projects-grid">
        {projects.length === 0 && <p>{ar ? 'ستظهر المشاريع هنا عند نشرها.' : 'Published projects will appear here.'}</p>}
        {projects.map(project => <article className="project-card" key={project.repository}>
            <div className="project-top"><span className="icon-tile">{icon(project.icon, 38)}</span><span className="status">{ar ? 'قيد التطوير' : 'In development'}</span></div>
            <h3>{project.title}</h3><p>{project.summary}</p>
            <div className="project-bottom"><span>{icon('android', 20)} Android</span><a href={`https://github.com/${project.repository}`}>{icon('github', 18)} <span>{ar ? 'المستودع على GitHub' : 'View on GitHub'}</span><Arrow /></a></div>
        </article>)}
    </div>;
}

const contribution = {
    quran: ['مراجعة القرآن', 'Qur’an review'],
    content: ['مراجعة المحتوى', 'Content review'],
    language: ['الترجمة', 'Translation'],
    security: ['الاختبارات', 'Testing'],
    projects: ['التطوير', 'Development'],
};

function Submission({ locale, page, notice, developmentTopic }: {locale: Locale; page: string; notice?: string; developmentTopic?: boolean}) {
    const ar = locale === 'ar';
    const form = useForm({ name: '', email: '', message: '', topic: developmentTopic ? 'projects' : '', consent: false, website: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(pageHref(locale, page), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return <form id="contribution-form" className="panel submission form-stack" onSubmit={submit}>
        <Notice>{notice}</Notice>
        <div className="form-grid">
            <Field label={ar ? 'الاسم' : 'Name'} error={form.errors.name}><input required maxLength={120} autoComplete="name" value={form.data.name} onChange={e => form.setData('name', e.target.value)} /></Field>
            <Field label={ar ? 'البريد الإلكتروني' : 'Email'} error={form.errors.email}><input dir="ltr" required maxLength={254} type="email" autoComplete="email" value={form.data.email} onChange={e => form.setData('email', e.target.value)} /></Field>
        </div>
        {page === 'contribute' && <Field label={ar ? 'مجال المساهمة' : 'Area of contribution'} error={form.errors.topic}>
            <select required value={form.data.topic} onChange={e => form.setData('topic', e.target.value)}>
                <option value="">{ar ? 'اختر مجالًا' : 'Choose an area'}</option>
                {Object.entries(contribution).map(([key, labels]) => <option value={key} key={key}>{labels[ar ? 0 : 1]}</option>)}
            </select>
        </Field>}
        <Field label={ar ? 'رسالتك' : 'Message'} error={form.errors.message}><textarea rows={5} required minLength={10} maxLength={4000} value={form.data.message} onChange={e => form.setData('message', e.target.value)} /></Field>
        <div hidden><label>Website<input tabIndex={-1} autoComplete="off" value={form.data.website} onChange={e => form.setData('website', e.target.value)} /></label></div>
        <label className="check"><input required type="checkbox" checked={form.data.consent} onChange={e => form.setData('consent', e.target.checked)} />
            {ar ? 'أوافق على معالجة بياناتي للردّ على طلبي.' : 'I agree to the use of my details to respond to this submission.'}</label>
        {form.errors.consent && <p className="error" role="alert">{form.errors.consent}</p>}
        <a className="text-link" href={pageHref(locale, 'privacy')}>{ar ? 'سياسة الخصوصية' : 'Privacy policy'}</a>
        <button type="submit" className="button primary" disabled={form.processing}>{ar ? 'إرسال إلى إرثي' : 'Send to Erthi'} <Arrow /></button>
    </form>;
}

const pillars = [
    ['quran', 'العناية بالمحتوى', 'Care for content', 'نولي مراجعة النصوص ودقة مصادرها عناية خاصة.', 'We prioritize careful review and accurate sourcing.'],
    ['learn', 'التعلّم الميسّر', 'Accessible learning', 'نسعى لتقديم أدوات واضحة وسهلة الاستخدام.', 'We aim to make our tools clear and easy to use.'],
    ['volunteer', 'المشاركة المفتوحة', 'Open collaboration', 'نرحّب بأصحاب الخبرة والراغبين في التعلّم والمساهمة.', 'We welcome specialists and people who want to learn and contribute.'],
];

export default function Public(props: PublicProps) {
    const { locale, page, content, projects, notice, developmentTopic } = props;
    const ar = locale === 'ar';
    useDirection(locale);

    return <>
        <Head title={props.seo.title} />
        <a className="skip" href="#main">{ar ? 'انتقل إلى المحتوى' : 'Skip to content'}</a>
        <Header {...props} />
        <main id="main">
            {page === 'home' ? <>
                <HomeScene locale={locale} title={content.home.title} description={content.home.body} />
                <section className="section shell">
                    <div className="section-heading"><div><p className="eyebrow"><i />{ar ? 'ما نعمل عليه' : 'WHAT WE BUILD'}</p><h2>{content.projects.title}</h2><p>{content.projects.body}</p></div><a className="text-link" href={pageHref(locale, 'projects')}>{ar ? 'كل المشاريع' : 'All projects'} <Arrow /></a></div>
                    <Projects locale={locale} projects={projects} />
                </section>
                <section className="section shell callout">
                    <div>{icon('development', 44)}<h2>{ar ? 'التطوير المفتوح' : 'Open development'}</h2><p>{content.contribute.body}</p></div>
                    <a className="button outline" href={developmentHref(locale)}>{ar ? 'طوّر معنا' : 'Develop with us'} <Arrow /></a>
                </section>
            </> : <section className="section shell inner">
                <p className="eyebrow">{page === 'contact' ? icon('mail', 23) : page === 'contribute' ? icon('development', 23) : <i />}{ar ? 'مبادرة إرثي' : 'ERTHI INITIATIVE'}</p>
                <h1>{content[page].title}</h1><p className="lead">{content[page].body}</p>
                {page === 'projects' && <Projects locale={locale} projects={projects} />}
                {page === 'about' && <div className="pillars">{pillars.map(([name, titleAr, titleEn, bodyAr, bodyEn]) =>
                    <article className="panel" key={name}>{icon(name, 38)}<h2>{ar ? titleAr : titleEn}</h2><p>{ar ? bodyAr : bodyEn}</p></article>
                )}</div>}
                {page === 'dream' && <a className="button primary" href={pageHref(locale, 'projects')}>{content.projects.title}<Arrow /></a>}
                {page === 'contribute' && <div className="topics">{Object.entries(contribution).map(([name, labels]) => <span key={name}>{icon(name, 25)} {labels[ar ? 0 : 1]}</span>)}</div>}
                {(page === 'contact' || page === 'contribute') && <Submission locale={locale} page={page} notice={notice} developmentTopic={developmentTopic} />}
                {page === 'privacy' && <a className="text-link" href="https://github.com/Erthi-Org/Erthi-Web-Platform/blob/main/LICENSE">{ar ? 'ترخيص مستودع الموقع' : 'Website repository license'} <Arrow /></a>}
            </section>}
        </main>
        <Footer {...props} />
    </>;
}
