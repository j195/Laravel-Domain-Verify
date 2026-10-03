const MAX_INPUT = 255;
const MAX_FILE_BYTES = 5 * 1024 * 1024;
const DKIM_PATTERN = /^[A-Za-z0-9](?:[A-Za-z0-9._-]{0,62})$/;

export function validateDomainOrEmail(value) {
    const input = (value || '').trim();

    if (!input) {
        return 'Enter a domain or email address.';
    }

    if (input.length > MAX_INPUT) {
        return 'The domain or email may not be longer than 255 characters.';
    }

    if (/\s/.test(input) || /[<>]/.test(input)) {
        return 'Enter a valid domain or email address.';
    }

    if (input.includes('@')) {
        const at = input.lastIndexOf('@');
        const local = input.slice(0, at);
        const domain = input.slice(at + 1).replace(/:\d+$/, '');

        if (!local || !looksLikeDomain(domain)) {
            return 'Enter a valid email address, or a domain such as example.com.';
        }

        return '';
    }

    const host = input.replace(/^https?:\/\//i, '').split('/')[0].replace(/:\d+$/, '');

    if (!looksLikeDomain(host) && !looksLikeIp(host)) {
        return 'Enter a valid domain or email address.';
    }

    return '';
}

export function validateDkimSelector(value) {
    const selector = (value || '').trim();

    if (!selector) {
        return '';
    }

    if (!DKIM_PATTERN.test(selector)) {
        return 'DKIM selector may only contain letters, numbers, dots, underscores, and hyphens.';
    }

    return '';
}

export function validateBulkFile(file) {
    if (!file) {
        return 'Upload a CSV or TXT file of domains or emails.';
    }

    const name = (file.name || '').toLowerCase();
    if (!name.endsWith('.csv') && !name.endsWith('.txt')) {
        return 'Only CSV or TXT files are allowed.';
    }

    if (file.size === 0) {
        return 'The file contains no domains or email addresses.';
    }

    if (file.size > MAX_FILE_BYTES) {
        return 'The upload may not be larger than 5 MB.';
    }

    return '';
}

function looksLikeDomain(value) {
    return /^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/i.test(value || '');
}

function looksLikeIp(value) {
    return /^(?:\d{1,3}\.){3}\d{1,3}$/.test(value || '');
}
