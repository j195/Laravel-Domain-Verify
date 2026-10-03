import { useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { api } from '../lib/api';
import { validateBulkFile, validateDkimSelector, validateDomainOrEmail } from '../lib/validation';
import Paginator from './Paginator';

const FILTERS = {
    blacklist: ['All', 'Clean', 'Blacklisted', 'Failed'],
    provider: ['All', 'Google Workspace', 'Microsoft 365', 'Other', 'Not Detected', 'Failed'],
};

const RESULT_PAGE_SIZE = 4;
const JOB_PAGE_SIZE = 3;

function StatusPill({ status }) {
    const map = {
        queued: 'bg-white/10 text-cyan-100',
        checking: 'bg-amber-300/15 text-amber-200',
        completed: 'bg-cyan-300/15 text-cyan-200',
        failed: 'bg-rose-400/15 text-rose-200',
    };

    return (
        <span className={`rounded-full px-2.5 py-1 text-xs capitalize ${map[status] || 'bg-white/10'}`}>
            {status}
        </span>
    );
}

function ResultDrawer({ type, item, onClose }) {
    if (!item) {
        return null;
    }

    const payload = item.payload || {};

    return (
        <div className="fixed inset-0 z-40 flex justify-end bg-black/50" onClick={onClose}>
            <aside
                className="h-full w-full max-w-xl overflow-y-auto border-l border-cyan-300/20 bg-[#071827] p-6"
                onClick={(event) => event.stopPropagation()}
            >
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <p className="text-xs uppercase tracking-[0.2em] text-cyan-300">Result</p>
                        <h3 className="mt-1 font-[Syne] text-2xl font-bold text-white">{item.domain || item.input}</h3>
                    </div>
                    <button onClick={onClose} className="rounded-full border border-white/15 px-3 py-1 text-sm">
                        Close
                    </button>
                </div>

                {item.error && <p className="mt-4 rounded-xl bg-rose-500/10 p-3 text-sm text-rose-200">{item.error}</p>}

                {type === 'blacklist' && payload.domain && (
                    <div className="mt-6 space-y-4 text-sm">
                        <Row label="Blacklist" value={`${payload.blacklist?.status}${payload.blacklist?.lists?.length ? ` · ${payload.blacklist.lists.map((l) => l.name).join(', ')}` : ''}`} />
                        <Row label="MX" value={`${payload.mx?.status}: ${(payload.mx?.records || []).map((r) => `${r.host} (${r.priority})`).join(', ') || '—'}`} />
                        <Row label="SPF" value={`${payload.spf?.status}${payload.spf?.value ? ` · ${payload.spf.value}` : ''}`} />
                        <Row label="DMARC" value={`${payload.dmarc?.status}${payload.dmarc?.value ? ` · ${payload.dmarc.value}` : ''}`} />
                        <Row label="DKIM" value={payload.dkim?.records?.length ? payload.dkim.records.map((r) => `${r.selector}: ${r.value}`).join('\n') : payload.dkim?.status || 'not checked'} />
                        <Row label="Nameservers" value={(payload.nameservers || []).join(', ') || '—'} />
                        <Row label="A / AAAA" value={[...(payload.a || []), ...(payload.aaaa || [])].join(', ') || '—'} />
                        <Row label="CNAME" value={(payload.cname || []).join(', ') || '—'} />
                        <Row label="PTR" value={(payload.ptr || []).map((r) => `${r.ip} → ${r.ptr || 'n/a'}`).join(', ') || '—'} />
                        <Row label="TXT" value={(payload.txt || []).join('\n') || '—'} />
                        {payload.all_records?.length > 0 && (
                            <Row
                                label="All DNS records"
                                value={payload.all_records
                                    .map((record) => {
                                        const recordType = record.type || 'REC';
                                        const host = record.host || record.target || record.ip || record.ipv6 || record.txt || '';
                                        return `${recordType} ${host}`.trim();
                                    })
                                    .join('\n')}
                            />
                        )}
                        {payload.errors?.length > 0 && <Row label="Errors / timeouts" value={payload.errors.join('\n')} />}
                    </div>
                )}

                {type === 'provider' && (payload.domain || item.domain) && (
                    <div className="mt-6 space-y-4 text-sm">
                        <Row label="Domain" value={payload.domain || item.domain} />
                        <Row label="Provider" value={payload.provider || '—'} />
                        <Row label="MX records found" value={formatMxRecords(payload.mx)} />
                        <Row label="Detection evidence/reason" value={formatEvidence(payload.evidence)} />
                        <Row label="Status" value={payload.status || '—'} />
                        {payload.errors?.length > 0 && <Row label="Errors / timeouts" value={payload.errors.join('\n')} />}
                    </div>
                )}
            </aside>
        </div>
    );
}

function formatMxRecords(records) {
    if (!Array.isArray(records) || records.length === 0) {
        return 'None found';
    }

    return records
        .map((record) => {
            const host = record.host || '—';
            return record.priority === null || record.priority === undefined
                ? host
                : `${host} (priority ${record.priority})`;
        })
        .join('\n');
}

function formatEvidence(evidence) {
    if (!Array.isArray(evidence) || evidence.length === 0) {
        return '—';
    }

    return evidence.map((line) => `• ${line}`).join('\n');
}

function Row({ label, value }) {
    return (
        <div className="rounded-xl border border-white/5 bg-white/5 p-3">
            <p className="text-xs uppercase tracking-[0.16em] text-cyan-200/60">{label}</p>
            <p className="mt-1 whitespace-pre-wrap text-cyan-50">{value}</p>
        </div>
    );
}

export default function CheckWorkbench({ type, title, subtitle, initialBatch, jobs: initialJobs = [], extraFields }) {
    const [input, setInput] = useState('');
    const [dkimSelector, setDkimSelector] = useState('');
    const [includeAll, setIncludeAll] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [fieldErrors, setFieldErrors] = useState({});
    const [batch, setBatch] = useState(initialBatch);
    const [jobs, setJobs] = useState(initialJobs);
    const [filter, setFilter] = useState('All');
    const [selected, setSelected] = useState(null);
    const [resultPage, setResultPage] = useState(1);
    const [jobPage, setJobPage] = useState(1);
    const [resultFade, setResultFade] = useState(true);
    const [jobFade, setJobFade] = useState(true);
    const batchId = batch?.id;
    const previousBatchId = useRef(null);

    const handoffBatch = (id) => {
        if (!id) {
            return;
        }

        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const body = new FormData();
        body.append('_token', csrf);
        const url = `/batches/${id}/handoff`;

        if (navigator.sendBeacon) {
            navigator.sendBeacon(url, body);
            return;
        }

        fetch(url, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            keepalive: true,
        }).catch(() => {});
    };

    useEffect(() => {
        if (previousBatchId.current && previousBatchId.current !== batchId) {
            handoffBatch(previousBatchId.current);
        }
        previousBatchId.current = batchId || null;
    }, [batchId]);

    useEffect(() => {
        if (!batchId || batch?.status === 'completed') {
            return undefined;
        }

        const onPageHide = () => handoffBatch(batchId);
        window.addEventListener('pagehide', onPageHide);
        window.addEventListener('beforeunload', onPageHide);

        const stopInertia = router.on('before', (event) => {
            const visit = event.detail.visit.url;
            const next = typeof visit === 'string' ? visit : visit.pathname || visit.toString();
            const stayingOnThisTool =
                (type === 'blacklist' && next.includes('/tools/blacklist')) ||
                (type === 'provider' && next.includes('/tools/provider'));

            if (!stayingOnThisTool) {
                handoffBatch(batchId);
            }
        });

        return () => {
            window.removeEventListener('pagehide', onPageHide);
            window.removeEventListener('beforeunload', onPageHide);
            stopInertia();
        };
    }, [batchId, batch?.status, type]);

    // Sequential ticks: wait for one domain to finish (or move to Checking) before requesting the next.
    // setInterval would overlap slow DNS calls and freeze the table until several rows finished.
    useEffect(() => {
        if (!batchId) {
            return undefined;
        }

        let cancelled = false;

        const step = async () => {
            try {
                const data = await api(`/batches/${batchId}/tick`, { method: 'POST' });
                if (cancelled) {
                    return;
                }

                setBatch(data.batch);
                rememberJob(data.batch);

                if (data.batch?.status !== 'completed') {
                    window.setTimeout(step, data.batch?.background ? 1000 : 200);
                }
            } catch (err) {
                if (!cancelled) {
                    setError(err.message);
                    window.setTimeout(step, 1500);
                }
            }
        };

        step();

        return () => {
            cancelled = true;
        };
    }, [batchId]);

    const filters = FILTERS[type];
    const items = batch?.items || [];
    const checked = batch?.completed || 0;
    const total = batch?.total || 0;
    const checkingItem = items.find((item) => item.status === 'checking');
    const progress = total > 0 ? Math.round((checked / total) * 100) : 0;

    const rememberJob = (nextBatch) => {
        if (!nextBatch?.id) {
            return;
        }

        const summary = {
            id: nextBatch.id,
            source: nextBatch.source,
            status: nextBatch.status,
            total: nextBatch.total,
            completed: nextBatch.completed,
            filename: nextBatch.filename,
            created_at: nextBatch.created_at,
        };

        setJobs((current) => [summary, ...current.filter((job) => job.id !== nextBatch.id)].slice(0, 50));
    };

    const openJob = async (id) => {
        setError('');
        try {
            const data = await api(`/batches/${id}`);
            setBatch(data.batch);
            rememberJob(data.batch);
            setResultPage(1);
            const path = type === 'provider' ? '/tools/provider' : '/tools/blacklist';
            window.history.replaceState({}, '', `${path}?batch=${id}`);
        } catch (err) {
            setError(err.message);
        }
    };

    const visible = useMemo(() => {
        return items.filter((item) => {
            if (filter === 'All') return true;
            if (filter === 'Failed') return item.status === 'failed';
            return (item.label || '') === filter;
        });
    }, [items, filter]);

    const resultPageCount = Math.max(1, Math.ceil(visible.length / RESULT_PAGE_SIZE));
    const safeResultPage = Math.min(resultPage, resultPageCount);
    const pagedResults = visible.slice((safeResultPage - 1) * RESULT_PAGE_SIZE, safeResultPage * RESULT_PAGE_SIZE);

    const jobPageCount = Math.max(1, Math.ceil(jobs.length / JOB_PAGE_SIZE));
    const safeJobPage = Math.min(jobPage, jobPageCount);
    const pagedJobs = jobs.slice((safeJobPage - 1) * JOB_PAGE_SIZE, safeJobPage * JOB_PAGE_SIZE);

    useEffect(() => {
        setResultPage(1);
    }, [filter, batchId]);

    useEffect(() => {
        if (!checkingItem) {
            return;
        }

        const index = visible.findIndex((item) => item.id === checkingItem.id);
        if (index >= 0) {
            setResultPage(Math.floor(index / RESULT_PAGE_SIZE) + 1);
        }
    }, [checkingItem?.id, visible]);

    const changeResultPage = (next) => {
        setResultFade(false);
        window.setTimeout(() => {
            setResultPage(next);
            setResultFade(true);
        }, 140);
    };

    const changeJobPage = (next) => {
        setJobFade(false);
        window.setTimeout(() => {
            setJobPage(next);
            setJobFade(true);
        }, 140);
    };

    const runSingle = async (event) => {
        event.preventDefault();
        const nextErrors = {
            input: validateDomainOrEmail(input),
            dkim_selector: extraFields ? validateDkimSelector(dkimSelector) : '',
        };
        setFieldErrors(nextErrors);
        setError('');

        if (nextErrors.input || nextErrors.dkim_selector) {
            setError(nextErrors.input || nextErrors.dkim_selector);
            return;
        }

        setBusy(true);
        try {
            const data = await api('/checks/single', {
                method: 'POST',
                json: {
                    type,
                    input: input.trim(),
                    dkim_selector: dkimSelector.trim() || null,
                    include_all_records: includeAll,
                },
            });
            if (data.item) {
                setSelected(data.item);
            }
            setBatch(data.batch);
            rememberJob(data.batch);
            setResultPage(1);
            setJobPage(1);
        } catch (err) {
            setError(err.message);
        } finally {
            setBusy(false);
        }
    };

    const runBulk = async (event) => {
        const file = event.target.files?.[0];
        event.target.value = '';
        if (!file) {
            return;
        }

        const fileError = validateBulkFile(file);
        const selectorError = extraFields ? validateDkimSelector(dkimSelector) : '';
        setFieldErrors({ file: fileError, dkim_selector: selectorError });
        setError('');

        if (fileError || selectorError) {
            setError(fileError || selectorError);
            return;
        }

        setBusy(true);
        try {
            const formData = new FormData();
            formData.append('type', type);
            formData.append('file', file);
            if (dkimSelector.trim()) {
                formData.append('dkim_selector', dkimSelector.trim());
            }
            if (includeAll) {
                formData.append('include_all_records', '1');
            }
            const data = await api('/checks/bulk', { method: 'POST', formData });
            setBatch(data.batch);
            rememberJob(data.batch);
            setResultPage(1);
            setJobPage(1);
        } catch (err) {
            setError(err.message);
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="space-y-6">
            <p className="-mt-4 text-cyan-100/70">{subtitle}</p>

            <section className="grid gap-4 lg:grid-cols-5">
                <form onSubmit={runSingle} className="rounded-2xl border border-cyan-300/15 bg-[#071827]/80 p-5 lg:col-span-3">
                    <p className="text-xs uppercase tracking-[0.2em] text-cyan-300">Single check</p>
                    <div className="mt-4 flex flex-col gap-3 sm:flex-row">
                        <input
                            value={input}
                            onChange={(e) => {
                                setInput(e.target.value);
                                if (fieldErrors.input) {
                                    setFieldErrors((current) => ({ ...current, input: '' }));
                                }
                            }}
                            placeholder="domain.com or user@domain.com"
                            maxLength={255}
                            autoComplete="off"
                            spellCheck={false}
                            className={`flex-1 rounded-xl border bg-white/5 px-4 py-3 outline-none ring-cyan-300 focus:ring-2 ${
                                fieldErrors.input ? 'border-rose-400/60' : 'border-cyan-300/20'
                            }`}
                            required
                        />
                        <button disabled={busy} className="rounded-xl bg-cyan-300 px-5 py-3 font-semibold text-slate-950 disabled:opacity-60">
                            {busy ? 'Checking…' : 'Run now'}
                        </button>
                    </div>
                    {fieldErrors.input && <p className="mt-2 text-sm text-rose-300">{fieldErrors.input}</p>}
                    {extraFields && (
                        <div className="mt-4 flex flex-wrap gap-4 text-sm">
                            <label className="flex items-center gap-2">
                                DKIM selector
                                <input
                                    value={dkimSelector}
                                    onChange={(e) => setDkimSelector(e.target.value)}
                                    placeholder="google / selector1"
                                    maxLength={63}
                                    className={`rounded-lg border bg-white/5 px-3 py-2 ${
                                        fieldErrors.dkim_selector ? 'border-rose-400/60' : 'border-cyan-300/20'
                                    }`}
                                />
                            </label>
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={includeAll} onChange={(e) => setIncludeAll(e.target.checked)} />
                                Display all DNS records
                            </label>
                        </div>
                    )}
                </form>

                <label className="flex cursor-pointer flex-col justify-center rounded-2xl border border-dashed border-cyan-300/30 bg-[#071827]/60 p-5 lg:col-span-2">
                    <p className="text-xs uppercase tracking-[0.2em] text-cyan-300">Bulk upload</p>
                    <p className="mt-2 text-sm text-cyan-100/70">CSV or TXT. One domain or email per line. Results stream in as each row finishes.</p>
                    <input type="file" accept=".csv,.txt,text/plain,text/csv" className="mt-4 text-sm" onChange={runBulk} />
                    {fieldErrors.file && <p className="mt-2 text-sm text-rose-300">{fieldErrors.file}</p>}
                </label>
            </section>

            {error && <div className="rounded-xl bg-rose-500/10 px-4 py-3 text-sm text-rose-200">{error}</div>}

            {jobs.length > 0 && (
                <section className="rounded-2xl border border-cyan-300/15 bg-[#071827]/80 p-5">
                    <h2 className="font-[Syne] text-xl font-semibold text-white">Previous jobs</h2>
                    <p className="mt-1 text-sm text-cyan-100/70">
                        Older uploads stay saved. Open any job to see its domains again. A new check still becomes the active table.
                    </p>
                    <div className={`mt-4 flex flex-col gap-2 transition-opacity duration-200 ${jobFade ? 'opacity-100' : 'opacity-0'}`}>
                        {pagedJobs.map((job) => {
                            const active = job.id === batch?.id;
                            const label = job.filename || (job.source === 'bulk' ? 'Bulk upload' : 'Single check');
                            const when = job.created_at ? new Date(job.created_at).toLocaleString() : '';

                            return (
                                <button
                                    key={job.id}
                                    type="button"
                                    onClick={() => openJob(job.id)}
                                    className={`flex flex-wrap items-center justify-between gap-2 rounded-xl border px-4 py-3 text-left text-sm ${
                                        active
                                            ? 'border-cyan-300/50 bg-cyan-300/10 text-white'
                                            : 'border-white/10 bg-white/5 text-cyan-100 hover:border-cyan-300/30'
                                    }`}
                                >
                                    <span>
                                        #{job.id} · {label}
                                        {when ? ` · ${when}` : ''}
                                    </span>
                                    <span className="text-cyan-200/70">
                                        {job.completed}/{job.total} · {job.status}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                    <Paginator
                        page={safeJobPage}
                        pageCount={jobPageCount}
                        onChange={changeJobPage}
                        summary={`Showing ${(safeJobPage - 1) * JOB_PAGE_SIZE + (jobs.length ? 1 : 0)}-${Math.min(safeJobPage * JOB_PAGE_SIZE, jobs.length)} of ${jobs.length} jobs`}
                    />
                </section>
            )}

            <section className="rounded-2xl border border-cyan-300/15 bg-[#071827]/80 p-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="font-[Syne] text-xl font-semibold text-white">{title} results</h2>
                        <p className="text-sm text-cyan-100/70">
                            {(checked || 0).toLocaleString()} / {(total || items.length).toLocaleString()} checked
                            {batch?.background
                                ? ' · finishing in the background'
                                : checkingItem
                                  ? ` · checking ${checkingItem.domain || checkingItem.input}`
                                  : batch?.status
                                    ? ` · ${batch.status}`
                                    : ''}
                        </p>
                        {total > 0 && batch?.status !== 'completed' && (
                            <div className="mt-2 h-1.5 w-56 overflow-hidden rounded-full bg-white/10">
                                <div className="h-full rounded-full bg-cyan-300 transition-all duration-300" style={{ width: `${progress}%` }} />
                            </div>
                        )}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {filters.map((name) => (
                            <button
                                key={name}
                                onClick={() => {
                                    setFilter(name);
                                    setResultPage(1);
                                }}
                                className={`rounded-full px-3 py-1.5 text-sm ${filter === name ? 'bg-cyan-300 text-slate-950' : 'bg-white/5 text-cyan-100'}`}
                            >
                                {name}
                            </button>
                        ))}
                        {batch?.id && (
                            <a
                                href={`/batches/${batch.id}/export`}
                                className="rounded-full border border-cyan-300/30 px-3 py-1.5 text-sm text-cyan-100"
                            >
                                Export CSV
                            </a>
                        )}
                    </div>
                </div>

                <div className={`mt-4 overflow-x-auto transition-opacity duration-200 ${resultFade ? 'opacity-100' : 'opacity-0'}`}>
                    <table className="w-full text-left text-sm">
                        <thead className="text-cyan-200/60">
                            <tr>
                                <th className="pb-2">Domain</th>
                                {type === 'provider' ? (
                                    <>
                                        <th className="pb-2">Provider</th>
                                        <th className="pb-2">MX records found</th>
                                        <th className="pb-2">Detection evidence/reason</th>
                                        <th className="pb-2">Status</th>
                                    </>
                                ) : (
                                    <th className="pb-2">Result</th>
                                )}
                                <th className="pb-2">Pipeline</th>
                                <th className="pb-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {pagedResults.length === 0 && (
                                <tr>
                                    <td colSpan={type === 'provider' ? 7 : 4} className="py-8 text-cyan-100/60">
                                        No rows yet. Run a single check or upload a list.
                                    </td>
                                </tr>
                            )}
                            {pagedResults.map((item) => (
                                <tr
                                    key={item.id}
                                    className={`border-t border-white/5 ${
                                        item.status === 'checking' ? 'bg-amber-300/10' : ''
                                    }`}
                                >
                                    <td className="py-3 align-top">
                                        <div className="font-medium text-white">{item.domain || item.input}</div>
                                        {item.domain && item.input !== item.domain && (
                                            <div className="text-xs text-cyan-200/50">{item.input}</div>
                                        )}
                                    </td>
                                    {type === 'provider' ? (
                                        <>
                                            <td className="py-3 align-top">{item.payload?.provider || item.label || '—'}</td>
                                            <td className="max-w-[16rem] py-3 align-top whitespace-pre-wrap text-cyan-100/90">
                                                {item.payload ? formatMxRecords(item.payload.mx) : '—'}
                                            </td>
                                            <td className="max-w-[18rem] py-3 align-top whitespace-pre-wrap text-cyan-100/90">
                                                {item.payload ? formatEvidence(item.payload.evidence) : '—'}
                                            </td>
                                            <td className="py-3 align-top">{item.payload?.status || '—'}</td>
                                        </>
                                    ) : (
                                        <td className="py-3 align-top">{item.label || '—'}</td>
                                    )}
                                    <td className="py-3 align-top">
                                        <StatusPill status={item.status} />
                                    </td>
                                    <td className="py-3 align-top text-right">
                                        <button onClick={() => setSelected(item)} className="text-cyan-300 hover:underline">
                                            Details
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Paginator
                    page={safeResultPage}
                    pageCount={resultPageCount}
                    onChange={changeResultPage}
                    summary={
                        visible.length
                            ? `Showing ${(safeResultPage - 1) * RESULT_PAGE_SIZE + 1}-${Math.min(safeResultPage * RESULT_PAGE_SIZE, visible.length)} of ${visible.length} results`
                            : 'No results on this page'
                    }
                />
            </section>

            <ResultDrawer type={type} item={selected} onClose={() => setSelected(null)} />
        </div>
    );
}
