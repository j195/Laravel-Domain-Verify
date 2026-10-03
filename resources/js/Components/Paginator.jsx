function pageNumbers(current, total) {
    if (total <= 7) {
        return Array.from({ length: total }, (_, index) => index + 1);
    }

    const pages = new Set([1, total, current, current - 1, current + 1]);
    const sorted = [...pages].filter((page) => page >= 1 && page <= total).sort((a, b) => a - b);
    const output = [];

    sorted.forEach((page, index) => {
        if (index > 0 && page - sorted[index - 1] > 1) {
            output.push('…');
        }
        output.push(page);
    });

    return output;
}

export default function Paginator({ page, pageCount, onChange, summary }) {
    if (pageCount <= 1) {
        return summary ? <p className="mt-4 text-xs text-cyan-200/60">{summary}</p> : null;
    }

    const go = (next) => {
        if (next < 1 || next > pageCount || next === page) {
            return;
        }
        onChange(next);
    };

    return (
        <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p className="text-xs text-cyan-200/60">{summary}</p>
            <nav className="flex items-center gap-1" aria-label="Pagination">
                <button
                    type="button"
                    onClick={() => go(page - 1)}
                    disabled={page === 1}
                    className="rounded-full border border-white/10 px-3 py-1.5 text-sm text-cyan-100 transition hover:border-cyan-300/40 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    Prev
                </button>
                {pageNumbers(page, pageCount).map((entry, index) =>
                    entry === '…' ? (
                        <span key={`gap-${index}`} className="px-2 text-cyan-200/50">
                            …
                        </span>
                    ) : (
                        <button
                            key={entry}
                            type="button"
                            onClick={() => go(entry)}
                            className={`min-w-9 rounded-full px-3 py-1.5 text-sm transition ${
                                entry === page
                                    ? 'bg-cyan-300 text-slate-950'
                                    : 'border border-white/10 text-cyan-100 hover:border-cyan-300/40'
                            }`}
                        >
                            {entry}
                        </button>
                    ),
                )}
                <button
                    type="button"
                    onClick={() => go(page + 1)}
                    disabled={page === pageCount}
                    className="rounded-full border border-white/10 px-3 py-1.5 text-sm text-cyan-100 transition hover:border-cyan-300/40 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    Next
                </button>
            </nav>
        </div>
    );
}
