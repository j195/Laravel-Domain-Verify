export function firstValidationMessage(payload) {
    if (!payload || typeof payload !== 'object') {
        return typeof payload === 'string' && payload ? payload : 'Request failed.';
    }

    if (payload.errors && typeof payload.errors === 'object') {
        const firstField = Object.values(payload.errors).find((messages) => Array.isArray(messages) && messages[0]);
        if (firstField) {
            return firstField[0];
        }
    }

    return payload.message || 'Request failed.';
}

export async function api(url, { method = 'GET', json, formData } = {}) {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': token || '',
    };

    const options = { method, credentials: 'same-origin', headers };

    if (formData) {
        options.body = formData;
    } else if (json !== undefined) {
        headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(json);
    }

    const response = await fetch(url, options);
    const contentType = response.headers.get('content-type') || '';
    const payload = contentType.includes('application/json') ? await response.json() : await response.text();

    if (!response.ok) {
        throw new Error(firstValidationMessage(payload));
    }

    return payload;
}
