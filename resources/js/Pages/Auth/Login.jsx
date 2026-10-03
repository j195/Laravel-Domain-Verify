import { Head, useForm } from '@inertiajs/react';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email: 'admin@mailin.test',
        password: '',
        remember: true,
    });

    return (
        <div className="domain-grid flex min-h-screen items-center justify-center px-4 py-10">
            <Head title="Admin login" />
            <div className="grid w-full max-w-5xl overflow-hidden rounded-3xl border border-cyan-300/20 bg-[#071827]/85 shadow-2xl shadow-cyan-950/40 lg:grid-cols-2">
                <div className="relative hidden border-r border-cyan-300/10 p-10 lg:block">
                    <div className="globe-ring mx-auto mb-8 flex h-40 w-40 items-center justify-center rounded-full">
                        <div className="h-28 w-28 rounded-full border border-cyan-200/30 bg-gradient-to-br from-cyan-300/20 to-transparent" />
                    </div>
                    <p className="font-[Syne] text-4xl font-bold text-white">Inspect every domain like a mail operator.</p>
                    <p className="mt-4 text-cyan-100/75">
                        Blacklists, DNS, SPF, DKIM, DMARC, and Google / Microsoft 365 detection — with live bulk processing.
                    </p>
                </div>
                <form
                    className="p-8 lg:p-10"
                    onSubmit={(event) => {
                        event.preventDefault();
                        post('/login');
                    }}
                >
                    <p className="text-xs uppercase tracking-[0.3em] text-cyan-300">Restricted access</p>
                    <h1 className="mt-2 font-[Syne] text-3xl font-bold text-white">Admin sign in</h1>
                    <p className="mt-2 text-sm text-cyan-100/70">Only authenticated operators can run domain checks.</p>

                    <label className="mt-8 block text-sm text-cyan-100">
                        Email
                        <input
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            required
                            maxLength={255}
                            autoComplete="username"
                            className="mt-2 w-full rounded-xl border border-cyan-300/20 bg-white/5 px-4 py-3 text-white outline-none ring-cyan-300 focus:ring-2"
                        />
                        {errors.email && <span className="mt-1 block text-sm text-rose-300">{errors.email}</span>}
                    </label>

                    <label className="mt-5 block text-sm text-cyan-100">
                        Password
                        <input
                            type="password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            required
                            maxLength={255}
                            autoComplete="current-password"
                            className="mt-2 w-full rounded-xl border border-cyan-300/20 bg-white/5 px-4 py-3 text-white outline-none ring-cyan-300 focus:ring-2"
                        />
                        {errors.password && <span className="mt-1 block text-sm text-rose-300">{errors.password}</span>}
                    </label>

                    <label className="mt-4 flex items-center gap-2 text-sm text-cyan-100/80">
                        <input
                            type="checkbox"
                            checked={data.remember}
                            onChange={(e) => setData('remember', e.target.checked)}
                        />
                        Remember this workstation
                    </label>

                    <button
                        disabled={processing}
                        className="mt-8 w-full rounded-xl bg-cyan-300 px-4 py-3 font-semibold text-slate-950 hover:bg-cyan-200 disabled:opacity-60"
                    >
                        {processing ? 'Signing in…' : 'Enter control room'}
                    </button>
                </form>
            </div>
        </div>
    );
}
