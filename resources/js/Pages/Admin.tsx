import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Brand, Field, Notice, icon, useDirection, type Copy } from '../shared';

const sections = {
    overview: ['نظرة عامة', 'projects'],
    projects: ['المشاريع', 'projects'],
    content: ['المحتوى', 'content'],
    languages: ['اللغات', 'language'],
    contribute: ['طلبات التطوع', 'volunteer'],
    contact: ['التواصل', 'mail'],
    media: ['الصور', 'image'],
    identity: ['الهوية البصرية', 'settings'],
    seo: ['محركات البحث', 'seo'],
    users: ['المستخدمون والصلاحيات', 'users'],
} as const;
type Section = keyof typeof sections;
type Value = string | boolean | File | null;
type Values = Record<string, Value>;
type Input = {name: string; label: string; type?: string; options?: [string, string][]; optional?: boolean};
type Project = {id: number; title_ar: string; title_en: string; summary_ar: string; summary_en: string; repository: string; icon: string; published: boolean | number};
type Message = {id: number; name: string; email: string; topic: string | null; message: string; reviewed_at: string | null; created_at: string};
type Media = {id: number; name: string};
type User = {id: number; name: string; email: string; role: string};
type Page<T> = {data: T[]; prev_page_url: string | null; next_page_url: string | null; current_page: number; last_page: number};
type AdminProps = {
    section: Section; settings: Record<string, string>; logo: string | null; role: string; notice?: string;
    data: Record<string, number> | Record<string, Copy> | Page<Project> | Page<Message> | Page<Media> | Page<User> | [];
};

const adminBase = () => document.querySelector<HTMLMetaElement>('meta[name="erthi-admin-base"]')?.content || '/admin';
const adminHref = (suffix = '') => adminBase() + suffix;
const accessHref = () => adminBase() === '/admin' ? '/login' : adminHref('/access');
const exitHref = () => adminBase() === '/admin' ? '/logout' : adminHref('/exit');
const sectionHref = (section: Section) => adminHref(section === 'overview' ? '' : `/${section}`);

function Editor({ url, fields, initial, clear = false, submitLabel = 'حفظ التغييرات' }: {
    url: string; fields: Input[]; initial: Values; clear?: boolean; submitLabel?: string;
}) {
    const form = useForm<Values>(initial);
    function submit(e: FormEvent) {
        e.preventDefault();
        form.post(url, { preserveScroll: true, onSuccess: () => { if (clear) form.reset(); } });
    }

    return <form className="form-stack" onSubmit={submit}>
        {fields.map(({ name, label, type, options, optional }) => {
            const value = form.data[name];
            const error = form.errors[name];
            const text = typeof value === 'string' ? value : '';
            if (type === 'checkbox') return <div key={name}><label className="check">
                <input type="checkbox" checked={Boolean(value)} onChange={e => form.setData(name, e.target.checked)} />{label}
            </label>{error && <small className="error" role="alert">{error}</small>}</div>;

            return <Field key={name} label={label} error={error}>
                {options ? <select value={text} required={!optional} onChange={e => form.setData(name, e.target.value)}>
                    {options.map(([id, title]) => <option value={id} key={id}>{title}</option>)}
                </select> : type === 'textarea' ? <textarea rows={4} dir="auto" value={text} required={!optional} onChange={e => form.setData(name, e.target.value)} />
                : type === 'file' ? <input type="file" accept="image/png,image/jpeg,image/webp" required onChange={e => form.setData(name, e.target.files?.[0] ?? null)} />
                : <input type={type ?? 'text'} dir={type === 'email' ? 'ltr' : 'auto'} value={text} required={!optional}
                    autoComplete={type === 'password' ? (url === accessHref() ? 'current-password' : 'new-password') : type === 'email' ? 'email' : undefined}
                    onChange={e => form.setData(name, e.target.value)} />}
            </Field>;
        })}
        <button type="submit" className="button primary" disabled={form.processing}>{submitLabel}</button>
    </form>;
}

export function Login() {
    useDirection('ar');
    return <main className="login shell"><Head title="دخول الإدارة | إرثي" /><Brand locale="ar" /><section className="panel">
        {icon('security', 42)}<h1>دخول الإدارة</h1>
        <Editor url={accessHref()} initial={{email: '', password: ''}} submitLabel="تسجيل الدخول" fields={[
            {name: 'email', label: 'البريد الإلكتروني', type: 'email'}, {name: 'password', label: 'كلمة المرور', type: 'password'},
        ]} />
    </section></main>;
}

function Pager<T>({ page }: {page: Page<T>}) {
    return page.last_page > 1 && <nav className="pagination" aria-label="صفحات السجل">
        {page.prev_page_url && <Link href={page.prev_page_url}>السابق</Link>}
        <span>{page.current_page} / {page.last_page}</span>
        {page.next_page_url && <Link href={page.next_page_url}>التالي</Link>}
    </nav>;
}

const projectFields: Input[] = [
    {name: 'title_ar', label: 'الاسم بالعربية'}, {name: 'title_en', label: 'الاسم بالإنجليزية'},
    {name: 'summary_ar', label: 'الوصف بالعربية', type: 'textarea'}, {name: 'summary_en', label: 'الوصف بالإنجليزية', type: 'textarea'},
    {name: 'repository', label: 'مستودع GitHub بصيغة owner/repo'},
    {name: 'icon', label: 'الأيقونة', options: [['quran', 'مصحف'], ['learn', 'تعليم'], ['review', 'مراجعة'], ['projects', 'مشروع'], ['android', 'Android']]},
    {name: 'published', label: 'عرض المشروع للزوار', type: 'checkbox'},
];
const emptyProject = {title_ar: '', title_en: '', summary_ar: '', summary_en: '', repository: '', icon: 'quran', published: true};

function ProjectEditor({ page }: {page: Page<Project>}) {
    return <>
        <details className="panel"><summary>إضافة مشروع</summary><Editor url={adminHref('/projects')} fields={projectFields} initial={emptyProject} clear /></details>
        {page.data.map(({ id, published, ...project }) => <details className="panel" key={id}>
            <summary>{project.title_ar} <bdi>{project.repository}</bdi></summary>
            <Editor url={adminHref(`/projects/${id}`)} fields={projectFields} initial={{...project, published: Boolean(published)}} />
        </details>)}
        <Pager page={page} />
    </>;
}

function ContentEditor({ copy }: {copy: Record<string, Copy>}) {
    const [locale, setLocale] = useState('ar');
    const [page, setPage] = useState('home');
    return <section className="panel form-stack">
        <div className="form-grid">
            <Field label="اللغة"><select value={locale} onChange={e => setLocale(e.target.value)}><option value="ar">العربية</option><option value="en">English</option></select></Field>
            <Field label="الصفحة"><select value={page} onChange={e => setPage(e.target.value)}>
                {Object.entries(copy[locale]).map(([key, block]) => <option value={key} key={key}>{block.title}</option>)}
            </select></Field>
        </div>
        <Editor key={`${locale}/${page}`} url={adminHref('/content')} fields={[{name: 'title', label: 'العنوان'}, {name: 'body', label: 'النص', type: 'textarea'}]}
            initial={{locale, page, ...copy[locale][page]}} />
    </section>;
}

function Inbox({ page, section }: {page: Page<Message>; section: 'contact' | 'contribute'}) {
    const topics: Record<string, string> = {quran: 'مراجعة القرآن', content: 'مراجعة المحتوى', language: 'الترجمة', security: 'الاختبارات', projects: 'التطوير'};
    return <>
        {page.data.length === 0 && <p className="panel">لا توجد رسائل في هذا القسم حتى الآن.</p>}
        {page.data.map(item => {
            const url = adminHref(`/submissions/${section}/${item.id}`);
            return <article key={item.id} className="panel message">
                <h2>{item.name}</h2><p><a href={`mailto:${item.email}`}><bdi>{item.email}</bdi></a></p>
                {item.topic && <p>{topics[item.topic]}</p>}<p className="message-body" dir="auto">{item.message}</p>
                <p><bdi>{item.created_at}</bdi> · {item.reviewed_at ? 'تمت المراجعة' : 'بانتظار المراجعة'}</p>
                <div className="actions">
                    {!item.reviewed_at && <button className="button outline" onClick={() => router.post(url, {}, {preserveScroll: true})}>تمت المراجعة</button>}
                    <button className="button outline" onClick={() => { if (window.confirm('حذف الرسالة نهائيًا؟')) router.delete(url, {preserveScroll: true}); }}>حذف الرسالة</button>
                </div>
            </article>;
        })}
        <Pager page={page} />
    </>;
}

function MediaEditor({ page }: {page: Page<Media>}) {
    return <>
        <section className="panel"><h2>رفع صورة</h2><p>JPG، PNG أو WebP حتى 4 ميغابايت.</p>
            <Editor url={adminHref('/media')} fields={[{name: 'name', label: 'اسم الصورة'}, {name: 'image', label: 'الملف', type: 'file'}]}
                initial={{name: '', image: null}} clear />
        </section>
        <div className="media-grid">{page.data.map(item => <figure className="panel" key={item.id}>
            <img loading="lazy" src={`/media/${item.id}`} alt="" width={160} height={120} />
            <figcaption>{item.name}</figcaption>
        </figure>)}</div>
        <Pager page={page} />
    </>;
}

function Identity({ page, settings, logo }: {page: Page<Media>; settings: Record<string, string>; logo: string | null}) {
    const choices: [string, string][] = [['', 'الختم الرسمي'], ...page.data.map(item => [String(item.id), item.name] as [string, string])];
    if (settings.logo_id && !choices.some(([id]) => id === settings.logo_id)) choices.push([settings.logo_id, 'الصورة الحالية']);
    return <section className="panel form-stack"><h2>شعار الموقع</h2>
        <img className="identity-preview" width="140" height="140" src={logo ?? '/brand/seal-dark.webp'} alt="الشعار الحالي" />
        <Editor key={settings.logo_id ?? 'default'} url={adminHref('/settings/identity')} fields={[{name: 'logo_id', label: 'الصورة المعتمدة', options: choices, optional: true}]}
            initial={{logo_id: settings.logo_id ?? ''}} />
        <Link className="text-link" href={adminHref('/media')}>رفع صورة جديدة</Link>
        <Pager page={page} />
    </section>;
}

const roles: [string, string][] = [['editor', 'محرّر'], ['viewer', 'موقوف عن الإدارة']];
function Users({ page }: {page: Page<User>}) {
    return <>
        <details className="panel"><summary>إنشاء حساب</summary><Editor url={adminHref('/users')} clear initial={{name: '', email: '', password: '', role: 'editor'}}
            fields={[{name: 'name', label: 'الاسم'}, {name: 'email', label: 'البريد الإلكتروني', type: 'email'}, {name: 'password', label: 'كلمة المرور (14 حرفًا على الأقل)', type: 'password'}, {name: 'role', label: 'الصلاحية', options: roles}]} /></details>
        {page.data.map(user => <article className="panel" key={user.id}><h2>{user.name}</h2><p><bdi>{user.email}</bdi></p>
            {user.role === 'owner' ? <p>مالك</p> : <Editor url={adminHref(`/users/${user.id}`)} fields={[{name: 'role', label: 'الصلاحية', options: roles}]} initial={{role: user.role}} />}
        </article>)}
        <Pager page={page} />
    </>;
}

function Workspace({ section, data, settings, logo }: AdminProps) {
    switch (section) {
        case 'overview': return <div className="admin-cards">{Object.entries(data as Record<string, number>).map(([key, count]) => <Link className="panel admin-card" href={sectionHref(key as Section)} key={key}>
            {icon(sections[key as Section][1], 36)}<span>{sections[key as Section][0]}</span><strong>{count}</strong>
        </Link>)}</div>;
        case 'projects': return <ProjectEditor page={data as Page<Project>} />;
        case 'content': return <ContentEditor copy={data as Record<string, Copy>} />;
        case 'contact':
        case 'contribute': return <Inbox page={data as Page<Message>} section={section} />;
        case 'media': return <MediaEditor page={data as Page<Media>} />;
        case 'identity': return <Identity page={data as Page<Media>} logo={logo} settings={settings} />;
        case 'users': return <Users page={data as Page<User>} />;
        case 'languages': return <section className="panel"><p>العربية هي اللغة الافتراضية للموقع.</p>
            <Editor url={adminHref('/settings/languages')} fields={[{name: 'english_enabled', label: 'إتاحة النسخة الإنجليزية', type: 'checkbox'}]} initial={{english_enabled: settings.english_enabled !== '0'}} />
        </section>;
        case 'seo': return <section className="panel"><h2>عنوان الموقع ووصفه</h2>
            <Editor url={adminHref('/settings/seo')} fields={[
                {name: 'title_ar', label: 'اسم الموقع بالعربية'}, {name: 'title_en', label: 'اسم الموقع بالإنجليزية'},
                {name: 'description_ar', label: 'الوصف بالعربية (160 حرفًا)', type: 'textarea'},
                {name: 'description_en', label: 'الوصف بالإنجليزية (160 حرفًا)', type: 'textarea'},
            ]} initial={{title_ar: settings.title_ar ?? 'إرثي', title_en: settings.title_en ?? 'Erthi',
                description_ar: settings.description_ar ?? '', description_en: settings.description_en ?? ''}} />
        </section>;
    }
}

export default function Admin(props: AdminProps) {
    useDirection('ar');
    const { section, logo, role, notice } = props;
    return <div className="admin-layout shell">
        <Head title={sections[section][0] + " | إرثي"} />
        <aside className="admin-nav">
            <Brand locale="ar" logo={logo} />
            <nav aria-label="إدارة الموقع">{(Object.keys(sections) as Section[]).filter(key => key !== 'users' || role === 'owner').map(key =>
                <Link key={key} href={sectionHref(key)} aria-current={section === key ? 'page' : undefined}>{icon(key === 'overview' ? 'projects' : sections[key][1], 25)}{sections[key][0]}</Link>
            )}</nav>
            <button type="button" className="button outline" onClick={() => router.post(exitHref())}>تسجيل الخروج</button>
        </aside>
        <main className="admin-main">
            <p className="eyebrow"><i />إدارة إرثي</p>
            <h1>{sections[section][0]}</h1>
            <Notice>{notice}</Notice>
            <Workspace key={section} {...props} />
        </main>
    </div>;
}
