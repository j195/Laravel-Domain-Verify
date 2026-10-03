import { Head, Link } from '@inertiajs/react';
import AppLayout from '../Layouts/AppLayout';

export default function Dashboard({ stats, recent }) {
    return (
        <AppLayout title="Domain control room">
            <Head title="Dashboard" />
            <p className="-mt-4 mb-8 max-w-2xl text-cyan-100/70">
                Run blacklist/DNS intelligence or detect Google Workspace and Microsoft 365 from public MX and SPF signals.
            </p>

            <div className="grid gap-4 md:grid-cols-3">
                {[
                    { label: 'Jobs launched', value: stats.batches },
                    { label: 'Domains submitted', value: stats.domains },
                    { label: 'Completed checks', value: stats.completed },
                ].map((card) => (
                    <div key={card.label} className="rounded-2xl border border-cyan-300/15 bg-[#071827]/70 p-5">
                        <p className="text-xs uppercase tracking-[0.2em] text-cyan-200/70">{card.label}</p>
                        <p className="mt-3 font-[Syne] text-4xl font-bold text-white">{card.value}</p>
                    </div>
                ))}
            </div>

            <div className="mt-8 grid gap-4 lg:grid-cols-2">
                <Link href="/tools/blacklist" className="rounded-2xl border border-cyan-300/20 bg-[#082033] p-6 hover:border-cyan-300/50">
                    <p className="text-xs uppercase tracking-[0.24em] text-cyan-300">Task 1</p>
                    <h2 className="mt-2 font-[Syne] text-2xl font-bold text-white">Blacklist + DNS Checker</h2>
                    <p className="mt-3 text-cyan-100/70">
                        DNSBL status, MX, SPF, DKIM, DMARC, A/AAAA, CNAME, NS, PTR, with single and bulk CSV/TXT intake.
                    </p>
                </Link>
                <Link href="/tools/provider" className="rounded-2xl border border-cyan-300/20 bg-[#082033] p-6 hover:border-cyan-300/50">
                    <p className="text-xs uppercase tracking-[0.24em] text-cyan-300">Task 2</p>
                    <h2 className="mt-2 font-[Syne] text-2xl font-bold text-white">Google Workspace / Microsoft 365 Detection</h2>
                    <p className="mt-3 text-cyan-100/70">
                        Classify domains as Google Workspace, Microsoft 365, other, or not detected using MX and SPF.
                    </p>
                </Link>
            </div>

            <section className="mt-8 rounded-2xl border border-cyan-300/15 bg-[#071827]/70 p-5">
                <h3 className="font-[Syne] text-xl font-semibold text-white">Recent jobs</h3>
                <div className="mt-4 overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="text-cyan-200/60">
                            <tr>
                                <th className="pb-2">ID</th>
                                <th className="pb-2">Tool</th>
                                <th className="pb-2">Source</th>
                                <th className="pb-2">Progress</th>
                                <th className="pb-2">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {recent.length === 0 && (
                                <tr>
                                    <td colSpan="5" className="py-6 text-cyan-100/60">
                                        No checks yet. Start with a single domain or a bulk upload.
                                    </td>
                                </tr>
                            )}
                            {recent.map((job) => (
                                <tr key={job.id} className="border-t border-white/5">
                                    <td className="py-3">
                                        <Link
                                            href={`/tools/${job.type === 'provider' ? 'provider' : 'blacklist'}?batch=${job.id}`}
                                            className="text-cyan-300 hover:underline"
                                        >
                                            #{job.id}
                                        </Link>
                                    </td>
                                    <td className="py-3">
                                        {job.type === 'provider' ? 'Google / Microsoft 365' : 'Blacklist + DNS'}
                                    </td>
                                    <td className="py-3 capitalize">{job.source}</td>
                                    <td className="py-3">
                                        {job.completed + job.failed} / {job.total}
                                    </td>
                                    <td className="py-3 capitalize">{job.status}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>
        </AppLayout>
    );
}
