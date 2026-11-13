import { Passkeys } from '@laravel/passkeys';
import { router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Field } from '../shared';

export type OwnerSecurity = {
    two_factor: 'disabled' | 'pending' | 'enabled';
    passkeys: {id: number; name: string}[];
};

async function request<T = void>(url: string, method: string, data?: Record<string, string>): Promise<T> {
    const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='));
    const response = await fetch(url, {
        method,
        headers: {'Accept': 'application/json', 'Content-Type': 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(cookie?.slice('XSRF-TOKEN='.length) ?? '')},
        body: data ? JSON.stringify(data) : undefined,
    });
    if (!response.ok || response.redirected) throw new Error('Request rejected');
    return response.headers.get('content-type')?.includes('application/json') && response.status !== 204
        ? response.json() : undefined as T;
}

export default function OwnerAuthentication({ security, base }: {security: OwnerSecurity; base: string}) {
    const [unlocked, setUnlocked] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(false);
    const [password, setPassword] = useState('');
    const [code, setCode] = useState('');
    const [name, setName] = useState('');
    const [qr, setQr] = useState('');
    const [recovery, setRecovery] = useState<string[]>([]);
    const reload = () => router.reload({only: ['data']});
    const factor = base + '/two-factor';
    const passkeys = base + '/passkeys';

    async function run(action: () => Promise<void>) {
        setBusy(true);
        setError(false);
        try {
            await action();
        } catch {
            setError(true);
        } finally {
            setBusy(false);
        }
    }

    function unlock(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const current = password;
        setPassword('');
        void run(async () => {
            await request(base + '/confirm-password', 'POST', {password: current});
            setUnlocked(true);
        });
    }

    return <>
        <section className="panel admin-widget">
            <h2>التحقق بخطوتين ومفاتيح المرور</h2>
            {error && <p className="error" role="alert">تعذّر تنفيذ الإجراء. تحقق من البيانات أو أعد تأكيد كلمة المرور.</p>}
            {!unlocked ? <form className="form-stack" onSubmit={unlock}>
                <Field label="كلمة مرور المالك الحالية"><input type="password" autoComplete="current-password" value={password} onChange={event => setPassword(event.target.value)} required /></Field>
                <button className="button outline" disabled={busy}>فتح إعدادات تسجيل الدخول</button>
            </form> : <div className="form-stack">
                <p>التحقق بخطوتين: {security.two_factor === 'enabled' ? 'مفعّل' : security.two_factor === 'pending' ? 'بانتظار التأكيد' : 'غير مفعّل'}</p>
                {security.two_factor !== 'enabled' && <button type="button" className="button outline" disabled={busy} onClick={() => void run(async () => {
                    if (security.two_factor === 'disabled') await request(factor, 'POST');
                    const result = await request<{svg: string}>(factor + '/qr', 'GET');
                    setQr(result.svg);
                    reload();
                })}>إعداد تطبيق المصادقة</button>}
                {qr && <form className="form-stack" onSubmit={event => {
                    event.preventDefault();
                    void run(async () => {
                        await request(factor + '/confirm', 'POST', {code});
                        setCode('');
                        setQr('');
                        reload();
                    });
                }}>
                    <img width="200" height="200" src={'data:image/svg+xml,' + encodeURIComponent(qr)} alt="رمز QR لتطبيق المصادقة" />
                    <Field label="الرمز من تطبيق المصادقة"><input dir="ltr" inputMode="numeric" autoComplete="one-time-code" value={code} onChange={event => setCode(event.target.value)} required /></Field>
                    <button className="button primary" disabled={busy}>تأكيد التفعيل</button>
                </form>}
                {security.two_factor === 'enabled' && <div className="actions">
                    <button type="button" className="button outline" disabled={busy} onClick={() => void run(async () => setRecovery(await request<string[]>(factor + '/recovery', 'GET')))}>عرض رموز الاسترداد</button>
                    <button type="button" className="button outline" disabled={busy} onClick={() => {
                        if (window.confirm('تعطيل التحقق بخطوتين لهذا الحساب؟')) void run(async () => {
                            await request(factor, 'DELETE');
                            setRecovery([]);
                            reload();
                        });
                    }}>تعطيل التحقق بخطوتين</button>
                </div>}
                {recovery.length > 0 && <ul dir="ltr">{recovery.map(value => <li key={value}><code>{value}</code></li>)}</ul>}
                <h3>مفاتيح المرور المسجّلة</h3>
                {security.passkeys.map(key => <div className="admin-security-row" key={key.id}>
                    <span dir="auto">{key.name}</span>
                    <button type="button" className="button outline" disabled={busy} onClick={() => {
                        if (window.confirm('حذف مفتاح المرور من حسابك؟')) void run(async () => {
                            await request(passkeys + '/' + key.id, 'DELETE');
                            reload();
                        });
                    }}>حذف المفتاح</button>
                </div>)}
                {Passkeys.isSupported() && <form className="form-stack" onSubmit={event => {
                    event.preventDefault();
                    void run(async () => {
                        await Passkeys.register({name: name.trim(), routes: {options: passkeys + '/options', submit: passkeys}});
                        setName('');
                        reload();
                    });
                }}>
                    <Field label="اسم مفتاح المرور"><input value={name} maxLength={100} onChange={event => setName(event.target.value)} required /></Field>
                    <button className="button primary" disabled={busy || !name.trim()}>إضافة مفتاح مرور</button>
                </form>}
            </div>}
        </section>
    </>;
}
