import { Head, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { useDirection } from '../shared';

export default function AuthenticationChallenge({ factor }: {factor: boolean}) {
    const [recovery, setRecovery] = useState(false);
    const form = useForm({code: '', recovery_code: '', password: ''});
    useDirection('ar');
    const title = factor ? 'التحقق بخطوتين' : 'تأكيد كلمة المرور';
    const error = Object.values(form.errors)[0];

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(window.location.pathname, {onFinish: () => form.reset()});
    }

    return <main className="erthi-auth" lang="ar" dir="rtl">
        <Head title={title + ' | إرثي'} />
        <section className="erthi-auth-card" aria-labelledby="erthi-challenge-title">
            <img className="erthi-auth-seal" src="/brand/seal-mobile.webp" width="80" height="80" alt="إرثي" />
            <h1 id="erthi-challenge-title">{title}</h1>
            <p>{factor ? 'أدخل الرمز من تطبيق المصادقة أو استخدم رمز استرداد.' : 'أدخل كلمة مرور حسابك لمتابعة إدارة إعدادات الأمان.'}</p>
            <form className="form-stack" onSubmit={submit}>
                {error && <p className="erthi-auth-error" role="alert">تعذّر التحقق. راجع البيانات وحاول مجددًا.</p>}
                <label className="field">
                    <span>{factor ? (recovery ? 'رمز الاسترداد' : 'رمز التحقق') : 'كلمة المرور'}</span>
                    <input dir="ltr" type={factor ? 'text' : 'password'} required
                        autoComplete={factor ? (recovery ? 'off' : 'one-time-code') : 'current-password'}
                        inputMode={factor && !recovery ? 'numeric' : undefined}
                        value={form.data[factor ? (recovery ? 'recovery_code' : 'code') : 'password']}
                        onChange={event => form.setData(factor ? (recovery ? 'recovery_code' : 'code') : 'password', event.target.value)} />
                </label>
                {factor && <button className="erthi-auth-link" type="button" onClick={() => {
                    form.reset();
                    form.clearErrors();
                    setRecovery(!recovery);
                }}>{recovery ? 'استخدام رمز التحقق' : 'استخدام رمز استرداد'}</button>}
                <button className="button primary erthi-auth-submit" disabled={form.processing}>تحقّق</button>
            </form>
        </section>
    </main>;
}
