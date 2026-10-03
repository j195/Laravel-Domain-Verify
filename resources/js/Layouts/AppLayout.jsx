import { Link, useForm, usePage } from '@inertiajs/react';

export default function AppLayout({ title, children }) {
    const { auth } = usePage().props;
    const { url } = usePage();
    const { post } = useForm({});
    const path = url || '';

    const nav = [
        { href: '/dashboard', label: 'Overview', match: '/dashboard' },
        { href: '/tools/blacklist', label: 'Blacklist + DNS', match: '/tools/blacklist' },
        { href: '/tools/provider', label: 'Google / Microsoft 365', match: '/tools/provider' },
    ];

    return (
        <div className="domain-grid min-h-screen">
            <div className="mx-auto flex min-h-screen max-w-7xl flex-col px-4 py-5 lg:px-8">
                <header className="relative z-50 mb-6 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-cyan-400/20 bg-[#071827]/80 px-5 py-4 backdrop-blur-md">
                    <div className="flex items-center gap-3">
                        <div className="globe-ring relative flex h-11 w-11 items-center justify-center rounded-full bg-cyan-400/10">
                            <span className="absolute inset-1 rounded-full border border-dashed border-cyan-300/40" />
                            <span className="h-2 w-2 rounded-full bg-cyan-300" />
                        </div>
                        <div>
                            <p className="font-[Syne] text-lg font-bold tracking-wide text-white">MAILIN</p>
                            <p className="text-xs uppercase tracking-[0.22em] text-cyan-200/70">Domain Checking Tools</p>
                        </div>
                    </div>

                    <nav className="flex flex-wrap items-center gap-2">
                        {nav.map((item) => {
                            const active = path.startsWith(item.match);
                            return (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    className={`rounded-full px-4 py-2 text-sm font-medium transition ${
                                        active
                                            ? 'bg-cyan-300 text-slate-950'
                                            : 'text-cyan-100 hover:bg-white/5'
                                    }`}
                                >
                                    {item.label}
                                </Link>
                            );
                        })}
                    </nav>

                    <div className="flex items-center gap-3 text-sm">
                        <div className="hidden text-right sm:block">
                            <p className="font-medium text-white">{auth.user?.name}</p>
                            <p className="text-xs text-cyan-200/70">{auth.user?.email}</p>
                        </div>
                        <button
                            type="button"
                            onClick={() => post('/logout')}
                            className="rounded-full border border-white/15 px-4 py-2 text-cyan-100 hover:border-cyan-300/50"
                        >
                            Sign out
                        </button>
                    </div>
                </header>

                <main className="flex-1 pb-10">
                    {title && (
                        <div className="mb-6">
                            <h1 className="font-[Syne] text-3xl font-bold text-white">{title}</h1>
                        </div>
                    )}
                    {children}
                </main>
            </div>
        </div>
    );
}
