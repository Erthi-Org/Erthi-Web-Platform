import { Head, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { useDirection, type Locale } from '../shared';

export default function Login() {
    const [locale, setLocale] = useState<Locale>('ar');
    const [showPassword, setShowPassword] = useState(false);
    const form = useForm({ email: '', password: '' });
    const arabic = locale === 'ar';
    const t = (ar: string, en: string) => arabic ? ar : en;
    const error = Object.values(form.errors)[0];
    useDirection(locale);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        form.transform(() => ({
            email: String(data.get('email') ?? '').trim(),
            password: String(data.get('password') ?? ''),
        }));
        form.post(window.location.pathname);
    }

    return <main className="erthi-auth" lang={locale} dir={arabic ? 'rtl' : 'ltr'}>
        <Head title={t('بوابة إدارة إرثي', 'Erthi Admin Portal')} />
        <section className="erthi-auth-card" aria-labelledby="erthi-auth-title">
            <img className="erthi-auth-seal" src="/brand/seal-mobile.webp" width="80" height="80" alt={t('إرثي', 'Erthi')} />
            <button className="erthi-auth-link" type="button" lang={arabic ? 'en' : 'ar'} onClick={() => setLocale(arabic ? 'en' : 'ar')}>
                {t('English', 'العربية')}
            </button>
            <p className="erthi-auth-kicker">{t('دخول الإدارة الخاص', 'PRIVATE ADMIN ACCESS')}</p>
            <h1 id="erthi-auth-title">{t('بوابة إدارة إرثي', 'Erthi Admin Portal')}</h1>
            <p>{t('سجّل الدخول بحساب إدارة موقع إرثي. حساب الاستضافة cPanel منفصل.', 'Sign in with your Erthi administrator account. Your cPanel hosting account is separate.')}</p>
            <form className="form-stack" method="post" onSubmit={submit}>
                {error && <p className="erthi-auth-error" role="alert">{t(error, 'Sign-in failed. Check your account details and try again.')}</p>}
                <label className="field">
                    <span>{t('البريد الإلكتروني', 'Email')}</span>
                    <input name="email" type="email" autoComplete="username" autoCapitalize="none" spellCheck={false} dir="ltr" required />
                </label>
                <label className="field">
                    <span>{t('كلمة المرور', 'Password')}</span>
                    <input name="password" id="erthi-login-password" type={showPassword ? 'text' : 'password'} autoComplete="current-password" dir="ltr" required />
                </label>
                <button className="erthi-auth-link erthi-password-toggle" type="button" aria-controls="erthi-login-password" onClick={() => setShowPassword(!showPassword)}>
                    {showPassword ? t('إخفاء كلمة المرور', 'Hide password') : t('إظهار كلمة المرور', 'Show password')}
                </button>
                <button className="button primary erthi-auth-submit" disabled={form.processing} type="submit">
                    {form.processing ? t('جارٍ التحقق…', 'Signing in…') : t('تسجيل الدخول', 'Sign in')}
                </button>
            </form>
        </section>
    </main>;
}
