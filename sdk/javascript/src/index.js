export class PeterApiError extends Error {
  constructor(message, status, payload) {
    super(message);
    this.name = 'PeterApiError';
    this.status = status;
    this.payload = payload;
    this.code = payload?.error?.code ?? null;
  }
}

export class Peter {
  constructor({
    application,
    apiKey = null,
    accessToken = null,
    baseUrl = 'https://api.petertecnet.com.br/api/v1',
    fetchImpl = globalThis.fetch,
  } = {}) {
    if (!application) throw new TypeError('application is required');
    if (!fetchImpl) throw new TypeError('A fetch implementation is required');
    this.application = application;
    this.apiKey = apiKey;
    this.accessToken = accessToken;
    this.baseUrl = baseUrl.replace(/\/$/, '');
    this.fetch = fetchImpl;
  }

  setAccessToken(accessToken) {
    this.accessToken = accessToken;
    return this;
  }

  async request(path, { method = 'GET', body, headers = {}, idempotencyKey } = {}) {
    const finalHeaders = { Accept: 'application/json', ...headers };
    if (body !== undefined) finalHeaders['Content-Type'] = 'application/json';
    if (this.apiKey) finalHeaders['X-API-Key'] = this.apiKey;
    if (this.accessToken) finalHeaders.Authorization = `Bearer ${this.accessToken}`;
    if (idempotencyKey) finalHeaders['Idempotency-Key'] = idempotencyKey;

    const response = await this.fetch(`${this.baseUrl}${path}`, {
      method,
      headers: finalHeaders,
      body: body === undefined ? undefined : JSON.stringify(body),
    });

    const text = await response.text();
    let payload = null;
    try { payload = text ? JSON.parse(text) : null; } catch { payload = { raw: text }; }
    if (!response.ok) {
      throw new PeterApiError(payload?.error?.message ?? payload?.message ?? `Peter API HTTP ${response.status}`, response.status, payload);
    }
    return payload;
  }

  appPath(path = '') {
    return `/apps/${encodeURIComponent(this.application)}${path}`;
  }

  listEstablishments() {
    return this.request(this.appPath('/establishments'));
  }

  listItems() {
    return this.request(this.appPath('/items'));
  }

  getCatalog(establishmentSlug) {
    return this.request(this.appPath(`/catalog/${encodeURIComponent(establishmentSlug)}`));
  }

  platform = {
    establishments: () => this.request(this.appPath('/platform/establishments')),
    items: () => this.request(this.appPath('/platform/items')),
    catalog: (slug) => this.request(this.appPath(`/platform/catalog/${encodeURIComponent(slug)}`)),
  };

  developer = {
    projects: () => this.request(this.appPath('/developer/projects')),
    createProject: (data, idempotencyKey = crypto.randomUUID()) =>
      this.request(this.appPath('/developer/projects'), { method: 'POST', body: data, idempotencyKey }),
    createApiKey: (projectId, data, idempotencyKey = crypto.randomUUID()) =>
      this.request(this.appPath(`/developer/projects/${projectId}/api-keys`), { method: 'POST', body: data, idempotencyKey }),
    createOauthClient: (projectId, data, idempotencyKey = crypto.randomUUID()) =>
      this.request(this.appPath(`/developer/projects/${projectId}/oauth-clients`), { method: 'POST', body: data, idempotencyKey }),
    usage: (projectId) => this.request(this.appPath(`/developer/projects/${projectId}/usage`)),
  };

  static async clientCredentials({ clientId, clientSecret, scope = '', baseUrl = 'https://api.petertecnet.com.br/api/v1', fetchImpl = globalThis.fetch }) {
    const response = await fetchImpl(`${baseUrl.replace(/\/$/, '')}/oauth/token`, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ grant_type: 'client_credentials', client_id: clientId, client_secret: clientSecret, scope }),
    });
    const payload = await response.json();
    if (!response.ok) throw new PeterApiError(payload?.error?.message ?? 'OAuth token exchange failed', response.status, payload);
    return payload;
  }
}

export default Peter;
