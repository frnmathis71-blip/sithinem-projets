// Requests from the interface are limited to the restaurant's desktop API; the session token stays in the main process.
const METHODS = new Set(['GET', 'POST', 'PATCH', 'DELETE']);
const IMAGE_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp']);
const MAX_IMAGE = 20 * 1024 * 1024;

function apiPath(path, query) {
  if (typeof path !== 'string' || path.length > 200 || !/^\/[a-z0-9-]+(?:\/[a-z0-9-]+)*$/.test(path)) throw new Error('Requête refusée.');
  const url = new URL('/api/desktop' + path, 'https://restaurant.invalid');
  if (query !== undefined && (query === null || typeof query !== 'object' || Array.isArray(query))) throw new Error('Requête refusée.');
  for (const [name, value] of Object.entries(query || {})) {
    if (!/^[a-z_]{1,30}$/.test(name) || !['string', 'number'].includes(typeof value) || String(value).length > 100) throw new Error('Requête refusée.');
    if (value !== '') url.searchParams.set(name, String(value));
  }
  return url.pathname + url.search;
}

async function apiBody(input) {
  if (!input || (input.json === undefined && !input.form)) return { headers: {}, body: undefined };
  if (input.form) {
    if (!Array.isArray(input.form) || input.form.length > 500) throw new Error('Formulaire invalide.');
    const form = new FormData();
    for (const entry of input.form) {
      if (!Array.isArray(entry) || entry.length !== 2 || typeof entry[0] !== 'string' || entry[0].length > 100) throw new Error('Formulaire invalide.');
      const [name, value] = entry;
      if (value && typeof value === 'object') {
        if (!/^(?:image|variants\[\d{1,2}\]\[image\])$/.test(name) || !IMAGE_TYPES.has(value.type) || !(value.data instanceof Uint8Array || value.data instanceof ArrayBuffer)) throw new Error('Photo refusée : utilisez une image JPG, PNG ou WebP.');
        const bytes = value.data instanceof ArrayBuffer ? new Uint8Array(value.data) : value.data;
        if (bytes.byteLength > MAX_IMAGE) throw new Error('Photo trop lourde : 20 Mo maximum.');
        form.append(name, new Blob([bytes], { type: value.type }), String(value.name || 'photo').slice(0, 120));
      } else {
        if (!['string', 'number', 'boolean'].includes(typeof value)) throw new Error('Formulaire invalide.');
        form.append(name, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
      }
    }
    const encoded = new Response(form);
    return { headers: { 'Content-Type': encoded.headers.get('content-type') }, body: Buffer.from(await encoded.arrayBuffer()) };
  }
  return { headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(input.json) };
}

function apiMethod(method) {
  if (!METHODS.has(method)) throw new Error('Requête refusée.');
  return method;
}

module.exports = { apiPath, apiBody, apiMethod };
