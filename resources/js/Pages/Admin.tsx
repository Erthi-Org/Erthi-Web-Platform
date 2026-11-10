import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import OwnerAuthentication, { type OwnerSecurity } from '../Components/OwnerAuthentication';
import { Brand, Field, Notice, icon, useDirection, type Copy } from '../shared';

const sections = {
    overview: 'نظرة عامة',
    projects: 'المشاريع',
    content: 'المحتوى',
    languages: 'اللغات',
    contribute: 'طلبات التطوع',
    contact: 'التواصل',
    media: 'الصور',
    identity: 'الهوية البصرية',
    seo: 'محركات البحث',
    users: 'المستخدمون والصلاحيات',
    security: 'أمان الإدارة',
    sessions: 'جلسات المالك',
    audit: 'سجل التدقيق',
    safescan: 'فحص الملفات',
    updates: 'تحديثات الموقع',
} as const;
type Section = keyof typeof sections;
const navigationGroups = [
    ['نظرة عامة', ['overview']],
    ['النشر', ['projects', 'content', 'languages', 'media']],
    ['التواصل والصلاحيات', ['contribute', 'contact', 'users']],
    ['الأمان', ['security', 'sessions', 'audit', 'safescan']],
    ['إعدادات الموقع', ['identity', 'seo', 'updates']],
] as const satisfies readonly (readonly [string, readonly Section[]])[];

type Value = string | boolean | File | null;
type Values = Record<string, Value>;
type Input = {name: string; label: string; type?: string; options?: [string, string][]; optional?: boolean};
type Project = {id: number; title_ar: string; title_en: string; summary_ar: string; summary_en: string; repository: string; icon: string; published: boolean | number};
type Message = {id: number; name: string; email: string; topic: string | null; message: string; reviewed_at: string | null; created_at: string};
type Media = {id: number; name: string};
type User = {id: number; name: string; email: string; role: string};
type Page<T> = {data: T[]; prev_page_url: string | null; next_page_url: string | null; current_page: number; last_page: number};
type SecurityStatus = OwnerSecurity & {secure_cookie: boolean; encrypted_session: boolean; database_sessions: boolean};
type OwnerSessions = {available: boolean; items: {current: boolean; last_activity: number}[]};
const ownerSections: readonly Section[] = ['users', 'security', 'sessions', 'audit', 'safescan', 'updates'];
const sectionIcon = (section: Section, size = 24) => icon(`admin-${section}`, size);
type AuditEvent = {at: string; actor: number; action: string; method: string; status: number};
type ScanStatus = {configured: boolean; result: 'clean' | 'detected' | 'unavailable' | null};
type ReleaseCheck = {status: 'available' | 'missing' | 'unavailable'; sequence: number; url?: string};
type UpdateStatus = {release_check: ReleaseCheck | null; key: string; sequence: number; active_sequence: number; can_rollback: boolean; available: boolean};
type AdminProps = {
    section: Section; settings: Record<string, string>; logo: string | null; role: string; notice?: string;
    data: Record<string, number> | Record<string, Copy> | Page<Project> | Page<Message> | Page<Media> | Page<User> | UpdateStatus | SecurityStatus | OwnerSessions | AuditEvent[] | ScanStatus | [];
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
                : type === 'file' ? <input type="file" accept={name === 'bundle' ? '.zip,application/zip' : 'image/png,image/jpeg,image/webp'} required onChange={e => form.setData(name, e.target.files?.[0] ?? null)} />
                : <input type={type ?? 'text'} dir={type === 'email' ? 'ltr' : 'auto'} value={text} required={!optional}
                    autoComplete={type === 'password' ? ((url === accessHref() || url.startsWith(adminHref('/updates'))) ? 'current-password' : 'new-password') : type === 'email' ? 'email' : undefined}
                    onChange={e => form.setData(name, e.target.value)} />}
            </Field>;
        })}
        <button type="submit" className="button primary" disabled={form.processing}>{submitLabel}</button>
    </form>;
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

const overviewCards = [
    ['projects', 'المشاريع المسجّلة'],
    ['contribute', 'طلبات تطوع بانتظار المراجعة'],
    ['contact', 'رسائل بانتظار المراجعة'],
    ['media', 'الصور المرفوعة'],
] as const;

const sectionDescriptions: Record<Section, string> = {
    overview: 'تابع الطلبات والرسائل وانتقل مباشرة إلى أعمال إدارة الموقع.',
    projects: 'أضف مشاريع الأثر وحدّث بياناتها وحالة ظهورها للزوار.',
    content: 'حرّر عناوين الصفحات ونصوصها حسب اللغة.',
    languages: 'تحكّم في إتاحة النسخة الإنجليزية للزوار.',
    contribute: 'راجع طلبات التطوع الواردة إلى المبادرة.',
    contact: 'اقرأ رسائل التواصل وتابع ما يحتاج إلى مراجعة.',
    media: 'ارفع صور الموقع واستعرض الملفات المتاحة.',
    identity: 'اختر الشعار الذي يظهر للزوار.',
    seo: 'اضبط عناوين الموقع وأوصافه لمحركات البحث.',
    users: 'أنشئ حسابات الإدارة وراجع صلاحياتها.',
    security: 'راجع إعدادات الحماية الفعلية لجلسات إدارة إرثي.',
    sessions: 'استعرض جلسات حساب المالك وأنهِ الجلسات الأخرى عند الحاجة.',
    audit: 'راجع طلبات التعديل الإدارية المسجّلة على الموقع.',
    safescan: 'افحص ملفًا بمحرك ClamAV المحلي قبل استخدامه.',
    updates: 'تحقّق من إصدارات GitHub وثبّت التحديث الموقّع من لوحة المالك.',
};

function Overview({ counts, owner }: { counts: Record<string, number>; owner: boolean }) {
    const inboxes = ['contribute', 'contact'] as const;
    const pending = counts.contribute + counts.contact;
    const shortcuts: Section[] = owner ? ['projects', 'content', 'security', 'updates'] : ['projects', 'content', 'identity', 'seo'];

    return <>
        <section className="admin-cards" aria-label="إحصاءات الموقع">
            {overviewCards.map(([key, label]) => <Link className="panel admin-card" href={sectionHref(key)} key={key}>
                {sectionIcon(key, 28)}<span>{label}</span><strong>{counts[key]}</strong>
            </Link>)}
        </section>
        <div className="admin-overview-grid">
            <section className="panel admin-widget">
                <h2>صندوق المتابعة</h2>
                <p>{pending === 0 ? 'كل الرسائل والطلبات الواردة تمت مراجعتها.' : 'توزيع الرسائل والطلبات التي تنتظر المراجعة.'}</p>
                {inboxes.map(key => <Link className="admin-queue" href={sectionHref(key)} key={key}>
                    {sectionIcon(key, 24)}<span>{sections[key]}</span><strong>{counts[key]}</strong>
                    <meter aria-label={sections[key]} min={0} max={Math.max(1, pending)} value={counts[key]} />
                </Link>)}
            </section>
            <section className="panel admin-widget">
                <h2>وصول سريع</h2>
                {shortcuts.map(key => <Link className="admin-shortcut" href={sectionHref(key)} key={key}>
                    {sectionIcon(key, 24)}<span>{sections[key]}</span><span aria-hidden="true">←</span>
                </Link>)}
            </section>
        </div>
    </>;
}

function ReleaseCheckPanel({ result, canInstall }: {result: ReleaseCheck | null; canInstall: boolean}) {
    const form = useForm({});
    const messages = {
        available: 'الحزمة التالية متاحة. يتحقق الموقع من توقيعها قبل تثبيتها.',
        missing: 'الحزمة التالية غير منشورة على GitHub حاليًا.',
        unavailable: 'تعذّر التحقق من GitHub. حاول مرة أخرى لاحقًا.',
    };
    return <div className="admin-rollback">
        <h2>إصدارات GitHub</h2>
        {result && <p role="status">{messages[result.status]}</p>}
        <div className="actions">
            <button type="button" className="button outline" disabled={form.processing} onClick={() => form.post(adminHref('/updates/check'), {preserveScroll: true})}>
                {form.processing ? 'جارٍ التحقق…' : 'تحقّق من وجود تحديث'}
            </button>
        </div>
        {result?.status === 'available' && (canInstall
            ? <Editor key={result.sequence} url={adminHref(`/updates/install-release/${result.sequence}`)} clear
                submitLabel={`تثبيت التحديث رقم ${result.sequence} من GitHub`}
                fields={[{name: 'password', label: 'كلمة مرور المالك الحالية', type: 'password'}]} initial={{password: ''}} />
            : <p>أكمل إعداد مفتاح التوقيع ومتطلبات الاستضافة لتثبيت التحديث.</p>)}
    </div>;
}

function Workspace({ section, data, settings, logo, role }: AdminProps) {
    switch (section) {
        case 'overview': return <Overview counts={data as Record<string, number>} owner={role === 'owner'} />;
        case 'projects': return <ProjectEditor page={data as Page<Project>} />;
        case 'content': return <ContentEditor copy={data as Record<string, Copy>} />;
        case 'contact':
        case 'contribute': return <Inbox page={data as Page<Message>} section={section} />;
        case 'media': return <MediaEditor page={data as Page<Media>} />;
        case 'identity': return <Identity page={data as Page<Media>} logo={logo} settings={settings} />;
        case 'security': {
            const status = data as SecurityStatus;
            const checks = [
                ['إرسال ملف الجلسة عبر HTTPS فقط', status.secure_cookie],
                ['تشفير بيانات الجلسة', status.encrypted_session],
                ['تخزين الجلسات في قاعدة البيانات', status.database_sessions],
            ] as const;
            return <> <section className="panel admin-widget">
                <h2>إعدادات حماية الجلسات</h2>
                {checks.map(([label, enabled]) => <div className="admin-security-row" key={label}>
                    <span>{label}</span><strong>{enabled ? 'مفعّل' : 'غير مفعّل'}</strong>
                </div>)}
                <Link className="text-link" href={sectionHref('sessions')}>عرض جلسات المالك</Link>
            </section><OwnerAuthentication security={status} base={adminBase()} /></>;
        }
        case 'sessions': {
            const sessions = data as OwnerSessions;
            const format = new Intl.DateTimeFormat('ar', {dateStyle: 'medium', timeStyle: 'short'});
            return <section className="panel admin-widget">
                <h2>آخر الجلسات النشطة</h2>
                {!sessions.available ? <p>تظهر الجلسات عند تخزينها في قاعدة البيانات.</p> : <>
                    {sessions.items.length === 0 && <p>لا توجد جلسات نشطة مسجّلة.</p>}
                    {sessions.items.map((session, index) => <div className="admin-security-row" key={index}>
                        <span>{session.current ? 'الجلسة الحالية' : 'جلسة أخرى'}</span>
                        <time dateTime={new Date(session.last_activity * 1000).toISOString()}>{format.format(session.last_activity * 1000)}</time>
                    </div>)}
                    {sessions.items.some(session => !session.current) && <button type="button" className="button outline" onClick={() => {
                        if (window.confirm('إنهاء جلسات حساب المالك الأخرى؟')) router.delete(sectionHref('sessions'));
                    }}>إنهاء الجلسات الأخرى</button>}
                </>}
            </section>;
        }
        case 'safescan': {
            const scan = data as ScanStatus;
            const results = {
                clean: 'لم يرصد محرك الفحص تهديدًا في هذا الملف.',
                detected: 'رصد محرك الفحص تهديدًا في الملف.',
                unavailable: 'تعذّر إكمال الفحص. تحقق من إعداد ClamAV على الاستضافة.',
            };
            return <section className="panel admin-widget">
                <h2>SafeScan</h2>
                <p>الحد الأقصى 10 ميغابايت. يُرسل الملف المؤقت إلى المحرك المحلي ويُعرض الحكم فقط.</p>
                {scan.result && <p role="status">{results[scan.result]}</p>}
                {scan.configured ? <Editor url={adminHref('/safescan')} clear submitLabel="فحص الملف"
                    fields={[{name: 'file', label: 'الملف', type: 'file'}]} initial={{file: null}} />
                    : <p>يتطلب الفحص إعداد مقبس ClamAV المحلي على الاستضافة.</p>}
            </section>;
        }
        case 'audit': {
            const events = data as AuditEvent[];
            const format = new Intl.DateTimeFormat('ar', {dateStyle: 'medium', timeStyle: 'short'});
            return <section className="panel admin-widget">
                <h2>آخر طلبات التعديل</h2>
                <p>يُعرض معرّف الحساب والإجراء وحالة الاستجابة.</p>
                {events.length === 0 && <p>لا توجد عمليات مسجّلة حتى الآن.</p>}
                {events.map((event, index) => <div className="admin-security-row" key={index}>
                    <span>حساب {event.actor} · <bdi>{event.method} {event.action} · HTTP {event.status}</bdi></span>
                    <time dateTime={event.at}>{format.format(new Date(event.at))}</time>
                </div>)}
            </section>;
        }
        case 'updates': {
            const update = data as UpdateStatus;
            return <section className="panel">
                <p>الإصدار النشط: {update.active_sequence} · رقم الحزمة التالية: {update.sequence + 1}</p>
                <ReleaseCheckPanel result={update.release_check} canInstall={update.available && Boolean(update.key)} />
                {!update.available ? <p>يلزم تفعيل امتدادي PHP ZIP وSodium على الاستضافة.</p> : update.key
                    ? <details className="admin-rollback">
                        <summary>رفع حزمة موقّعة يدويًا</summary>
                        <Editor url={adminHref('/updates')} clear submitLabel="تثبيت الحزمة المرفوعة"
                            fields={[{name: 'bundle', label: 'حزمة التحديث الموقّعة', type: 'file'},
                                {name: 'password', label: 'كلمة مرور المالك الحالية', type: 'password'}]}
                            initial={{bundle: null, password: ''}} />
                    </details>
                    : <Editor url={adminHref('/updates')} clear submitLabel="تسجيل مفتاح التوقيع"
                        fields={[{name: 'key', label: 'مفتاح التوقيع العام Base64'},
                            {name: 'password', label: 'كلمة مرور المالك الحالية', type: 'password'}]}
                        initial={{key: '', password: ''}} />}
                {update.can_rollback && <details className="admin-rollback">
                    <summary>استعادة ملفات الإصدار السابق</summary>
                    <p>استعادة ملفات آخر إصدار سابق. رقم الحزمة التالية يظل محفوظًا.</p>
                    <Editor url={adminHref('/updates/rollback')} clear submitLabel="استعادة الإصدار السابق"
                        fields={[{name: 'password', label: 'كلمة مرور المالك الحالية', type: 'password'}]} initial={{password: ''}} />
                </details>}
            </section>;
        }
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
    const [menuOpen, setMenuOpen] = useState(false);
    const owner = role === 'owner';
    const title = section === 'overview' ? (owner ? 'مركز إدارة المالك' : 'مركز إدارة إرثي') : sections[section];

    return <div className="admin-layout" dir="rtl">
        <Head title={title + ' | إرثي'} />
        <aside className="admin-nav">
            <div className="admin-nav-top">
                <Brand locale="ar" logo={logo} />
                <button type="button" className="button outline admin-menu-toggle" aria-controls="erthi-admin-nav" aria-expanded={menuOpen} onClick={() => setMenuOpen(!menuOpen)}>الأقسام</button>
            </div>
            <div id="erthi-admin-nav" className={menuOpen ? 'admin-nav-body is-open' : 'admin-nav-body'}>
                <nav aria-label="إدارة الموقع">{navigationGroups.map(([label, keys]) =>
                    <div className="admin-nav-group" key={label} hidden={!owner && keys.every(key => ownerSections.includes(key))}>
                        <p>{label}</p>
                        {keys.filter(key => owner || !ownerSections.includes(key)).map(key =>
                            <Link key={key} href={sectionHref(key)} aria-current={section === key ? 'page' : undefined} onClick={() => setMenuOpen(false)}>{sectionIcon(key, 24)}{sections[key]}</Link>
                        )}
                    </div>
                )}</nav>
                <button type="button" className="button outline admin-exit" onClick={() => router.post(exitHref())}>تسجيل الخروج</button>
            </div>
        </aside>
        <main className="admin-main">
            <div className="admin-topbar">
                <span>إدارة إرثي <span aria-hidden="true">/</span> {sections[section]}</span>
                <span className="admin-role">{sectionIcon('security', 20)}{owner ? 'حساب المالك' : 'حساب المحرّر'}</span>
            </div>
            <header className="admin-heading">
                <div>
                    <p className="eyebrow"><i />لوحة إدارة إرثي</p>
                    <h1>{title}</h1>
                    <p>{sectionDescriptions[section]}</p>
                </div>
                <a className="button outline" href="/ar">عرض الموقع</a>
            </header>
            <Notice>{notice}</Notice>
            <Workspace key={section} {...props} />
        </main>
    </div>;
}
