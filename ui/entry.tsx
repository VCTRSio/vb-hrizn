// ESM single-bundle UI for the extracted vb-hrizn plugin.
//
// Runtime model: React / ReactDOM / the host UI kit are injected via `host` at mount —
// this file NEVER `import`s react. The only things bundled into dist/entry.js are this
// code, the vendored @vctrs/plugin-ui client kit, and axios (pulled in by the kit).
// All chrome (DashboardLayout / PageContainer / Head) is dropped; the host provides it.
//
// Data layer: the vendored kit at ./plugin-ui/client.ts. `apiGet` unwraps the canonical
// {traceId,data,status} envelope; mutations use the axios instance directly so axios
// auto-attaches X-XSRF-TOKEN from the session cookie.
import { apiGet, createApiClient, ApiClientError } from './plugin-ui/client';

// PluginModule / Host are defined locally: the vendored client.ts does not export them
// (they lived in the kit's index, which was not vendored), and React must come from the
// host, so the Host type describes exactly what the loader passes to mount().
type Host = {
  React: typeof import('react');
  ReactDOM: typeof import('react-dom/client');
  ui: Record<string, any>;
};

type PluginModule = {
  mount(el: HTMLElement, host: Host, props: any): (() => void) | void;
};

// Internal navigation state (no URL router — list ↔ detail ↔ settings is component state).
type View =
  | { name: 'overview' }
  | { name: 'ideaclouds' }
  | { name: 'ideacloudShow'; id: string }
  | { name: 'content' }
  | { name: 'contentShow'; id: string }
  | { name: 'settings' };

// One session-cookie-authed client for the whole plugin surface.
const api = createApiClient('/api/v1/hrizn');

// ── Label / variant maps ported 1:1 from the source Inertia pages ──────────────
const ARTICLE_TYPE_LABELS: Record<string, string> = {
  basic: 'Standard Article',
  qa: 'Q&A Format',
  expert: 'Expert Article',
  modellanding: 'Model Landing Page',
  comparison: 'Comparison',
  salesevent: 'Sales Event',
  emailtemplate: 'Email Template',
};

const INTENT_LABELS: Record<string, string> = {
  fixed_ops: 'Fixed Ops',
  variable: 'Variable',
  general: 'General',
};

// Article types that describe a specific vehicle and can be VIN-linked (mirrors
// ContentController::VEHICLE_ARTICLE_TYPES) — only these show the vehicle picker.
const VEHICLE_ARTICLE_TYPES = ['modellanding', 'comparison'];

// One-line label for a picker vehicle: "{year} {make} {model} {trim} — {vin}".
function vehicleLabel(v: any): string {
  const head = [v.year, v.make, v.model, v.trim].filter((p: any) => p != null && p !== '').join(' ');
  return v.vin ? (head ? `${head} — ${v.vin}` : String(v.vin)) : head;
}

// shadcn Badge variants, mirrored from the source status helpers.
function contentStatusVariant(status: string): string {
  if (status === 'complete') return 'default';
  if (status === 'generating' || status === 'awaiting_input') return 'secondary';
  if (status === 'failed') return 'destructive';
  return 'outline';
}

function ideacloudStatusVariant(status: string): string {
  if (status === 'complete') return 'default';
  if (status === 'researching') return 'secondary';
  if (status === 'failed') return 'destructive';
  return 'outline';
}

function titleize(v: string): string {
  return v.replace(/_/g, ' ');
}

function fmtDate(v?: string | null): string {
  if (!v) return '—';
  const d = new Date(v);
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString();
}

const plugin: PluginModule = {
  mount(el, host, _props) {
    const R = host.React;
    const { Card, CardHeader, CardContent, CardTitle, Badge, Button } = host.ui;
    const root = host.ReactDOM.createRoot(el);

    // ── Inline SVG icons (self-bundled; no icon package) ─────────────────────────
    function IconSparkles() {
      return R.createElement(
        'svg',
        { width: 20, height: 20, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round' },
        R.createElement('path', { d: 'M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z' }),
        R.createElement('path', { d: 'M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z' }),
      );
    }

    function IconArrowLeft() {
      return R.createElement(
        'svg',
        { width: 16, height: 16, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round' },
        R.createElement('path', { d: 'M19 12H5M12 19l-7-7 7-7' }),
      );
    }

    // ── Tiny query hook: apiGet over host.React (the kit's useApiQuery was omitted
    // because it imports its own React; we build the same shape on host.React). ──
    function useQuery<T>(path: string | null, deps: any[] = []) {
      const [data, setData] = R.useState<T | null>(null);
      const [error, setError] = R.useState<string | null>(null);
      const [loading, setLoading] = R.useState<boolean>(path !== null);

      R.useEffect(() => {
        if (path === null) {
          setLoading(false);
          return;
        }
        let alive = true;
        setLoading(true);
        apiGet<T>(path, api)
          .then((d) => {
            if (alive) {
              setData(d);
              setError(null);
            }
          })
          .catch((e) => alive && setError(e instanceof ApiClientError ? e.message : String(e)))
          .finally(() => alive && setLoading(false));
        return () => {
          alive = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
      }, [path, ...deps]);

      return { data, error, loading };
    }

    // ── Shared presentational bits ───────────────────────────────────────────────
    function PageHeader({ title, description, actions }: { title: string; description?: string; actions?: any }) {
      return R.createElement(
        'div',
        { style: { display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16, flexWrap: 'wrap' } },
        R.createElement(
          'div',
          null,
          R.createElement(
            'div',
            { style: { display: 'flex', alignItems: 'center', gap: 8 } },
            R.createElement(IconSparkles, null),
            R.createElement('h1', { style: { fontSize: 24, fontWeight: 600, letterSpacing: '-0.01em' } }, title),
          ),
          description && R.createElement('p', { style: { fontSize: 14, opacity: 0.7, marginTop: 4 } }, description),
        ),
        actions && R.createElement('div', { style: { display: 'flex', gap: 8, flexWrap: 'wrap' } }, actions),
      );
    }

    function ErrorBanner({ error }: { error: string | null }) {
      if (!error) return null;
      return R.createElement('div', { 'data-testid': 'hrizn-error', style: { color: 'crimson', fontSize: 14, padding: '8px 0' } }, error);
    }

    function Loading() {
      return R.createElement('div', { style: { padding: 32, textAlign: 'center', fontSize: 14, opacity: 0.7 } }, 'Loading…');
    }

    function Empty({ text }: { text: string }) {
      return R.createElement('div', { style: { padding: 32, textAlign: 'center', fontSize: 14, opacity: 0.7 } }, text);
    }

    function NavButton({ label, onClick }: { label: string; onClick: () => void }) {
      return R.createElement(Button, { variant: 'outline', size: 'sm', onClick }, label);
    }

    function StatusBadge({ status, variant }: { status: string; variant: string }) {
      return R.createElement(Badge, { variant, style: { textTransform: 'capitalize' } }, titleize(status));
    }

    // "Ready to publish" pill — shown once content generation is complete.
    function ReadyBadge({ status }: { status: string }) {
      if (status !== 'complete') return null;
      return R.createElement(Badge, { variant: 'default', 'data-testid': 'hrizn-ready-badge' }, 'Ready to publish');
    }

    // ── Vehicle picker (session-API passthrough to inventory-hub) ────────────────
    // Types a query into GET /vehicles/search and lets the user pick a vehicle; the
    // chosen VIN is lifted to the generate form. Degrades to no suggestions when
    // inventory-hub is not installed (the endpoint returns []).
    function VehiclePicker({ vin, onSelect }: { vin: string; onSelect: (vin: string) => void }) {
      const [q, setQ] = R.useState('');
      const [results, setResults] = R.useState<any[]>([]);

      R.useEffect(() => {
        const term = q.trim();
        if (term === '') {
          setResults([]);
          return;
        }
        let alive = true;
        apiGet<any[]>('/vehicles/search?q=' + encodeURIComponent(term), api)
          .then((rows) => alive && setResults(Array.isArray(rows) ? rows.slice(0, 8) : []))
          .catch(() => alive && setResults([]));
        return () => {
          alive = false;
        };
      }, [q]);

      return R.createElement(
        'div',
        { 'data-testid': 'hrizn-vehicle-field', style: { display: 'grid', gap: 6 } },
        R.createElement('label', { style: { fontSize: 14, fontWeight: 500 } }, 'Vehicle (optional)'),
        vin
          ? R.createElement(
              'div',
              { style: { display: 'flex', alignItems: 'center', gap: 8 } },
              R.createElement(Badge, { variant: 'secondary', 'data-testid': 'hrizn-vehicle-selected' }, '🔗 ' + vin),
              R.createElement(Button, { variant: 'ghost', size: 'sm', onClick: () => onSelect('') }, 'Clear'),
            )
          : R.createElement(
              R.Fragment,
              null,
              R.createElement('input', {
                value: q,
                onChange: (e: any) => setQ(e.target.value),
                placeholder: 'Search inventory by make, model, or VIN',
                style: { padding: '8px 10px', border: '1px solid rgba(128,128,128,0.4)', borderRadius: 6, fontSize: 14 },
              }),
              results.length > 0 &&
                R.createElement(
                  'div',
                  { style: { display: 'grid', gap: 4 } },
                  results.map((v: any) =>
                    R.createElement(
                      'button',
                      {
                        key: v.vin,
                        type: 'button',
                        'data-testid': 'hrizn-vehicle-suggestion',
                        onClick: () => {
                          onSelect(String(v.vin));
                          setQ('');
                          setResults([]);
                        },
                        style: { textAlign: 'left', padding: '6px 8px', border: '1px solid rgba(128,128,128,0.3)', borderRadius: 6, fontSize: 13, background: 'transparent', cursor: 'pointer' },
                      },
                      vehicleLabel(v),
                    ),
                  ),
                ),
            ),
      );
    }

    // ── Content-generation form (POST /content) ──────────────────────────────────
    // Article type drives whether the vehicle picker renders; the VIN is only sent
    // for vehicle-specific article types (modellanding / comparison).
    function GenerateForm({ onDone }: { onDone: () => void }) {
      const [ideacloudId, setIdeacloudId] = R.useState('');
      const [articleType, setArticleType] = R.useState('basic');
      const [vehicleVin, setVehicleVin] = R.useState('');
      const [submitting, setSubmitting] = R.useState(false);
      const [formError, setFormError] = R.useState<string | null>(null);

      const isVehicleType = VEHICLE_ARTICLE_TYPES.includes(articleType);

      // Drop any selected VIN when leaving a vehicle-specific type so it's never sent.
      R.useEffect(() => {
        if (!isVehicleType && vehicleVin) setVehicleVin('');
        // eslint-disable-next-line react-hooks/exhaustive-deps
      }, [articleType]);

      function submit() {
        const ic = ideacloudId.trim();
        if (!ic || submitting) return;
        setSubmitting(true);
        setFormError(null);
        const payload: Record<string, any> = { ideacloudId: ic, articleType };
        if (isVehicleType && vehicleVin) payload.vehicleVin = vehicleVin;
        api
          .post('/content', payload)
          .then((r: any) => r.data.data)
          .then(() => {
            setIdeacloudId('');
            setVehicleVin('');
            onDone();
          })
          .catch((e: any) => setFormError(e?.response?.data?.error ?? String(e)))
          .finally(() => setSubmitting(false));
      }

      return R.createElement(
        Card,
        { 'data-testid': 'hrizn-generate-form' },
        R.createElement(CardHeader, null, R.createElement(CardTitle, { style: { fontSize: 16 } }, 'Generate Content')),
        R.createElement(
          CardContent,
          { style: { display: 'grid', gap: 8 } },
          R.createElement('label', { style: { fontSize: 14, fontWeight: 500 } }, 'IdeaCloud ID *'),
          R.createElement('input', {
            value: ideacloudId,
            onChange: (e: any) => setIdeacloudId(e.target.value),
            placeholder: 'IdeaCloud id to generate from',
            style: { padding: '8px 10px', border: '1px solid rgba(128,128,128,0.4)', borderRadius: 6, fontSize: 14 },
          }),
          R.createElement('label', { style: { fontSize: 14, fontWeight: 500 } }, 'Article Type'),
          R.createElement(
            'select',
            {
              value: articleType,
              'data-testid': 'hrizn-article-type',
              onChange: (e: any) => setArticleType(e.target.value),
              style: { padding: '8px 10px', border: '1px solid rgba(128,128,128,0.4)', borderRadius: 6, fontSize: 14 },
            },
            Object.keys(ARTICLE_TYPE_LABELS).map((t) => R.createElement('option', { key: t, value: t }, ARTICLE_TYPE_LABELS[t])),
          ),
          isVehicleType && R.createElement(VehiclePicker, { vin: vehicleVin, onSelect: setVehicleVin }),
          formError && R.createElement('p', { style: { fontSize: 13, color: 'crimson' } }, formError),
          R.createElement(
            'div',
            { style: { display: 'flex', gap: 8 } },
            R.createElement(Button, { size: 'sm', onClick: submit, disabled: submitting || !ideacloudId.trim() }, submitting ? 'Generating…' : 'Generate'),
          ),
        ),
      );
    }

    // ── Overview (GET /overview) — was Hrizn/Index ───────────────────────────────
    function Overview({ nav }: { nav: (v: View) => void }) {
      const { data, error, loading } = useQuery<{ stats: any; recentContent: any[] }>('/overview');
      const stats = data?.stats;
      const recent = data?.recentContent ?? [];

      const statCards: [string, any][] = stats
        ? [
            ['Total Content', stats.totalContent],
            ['Content This Month', stats.contentThisMonth],
            ['IdeaClouds', stats.ideacloudCount],
            ['In Progress', stats.inProgressCount],
          ]
        : [];

      return R.createElement(
        'div',
        { 'data-testid': 'hrizn-overview', style: { display: 'grid', gap: 16 } },
        R.createElement(PageHeader, {
          title: 'HRIZN',
          description: 'AI-powered automotive content generation.',
          actions: [
            R.createElement(NavButton, { key: 'ic', label: 'IdeaClouds', onClick: () => nav({ name: 'ideaclouds' }) }),
            R.createElement(NavButton, { key: 'ct', label: 'Content Library', onClick: () => nav({ name: 'content' }) }),
            R.createElement(NavButton, { key: 'st', label: 'Settings', onClick: () => nav({ name: 'settings' }) }),
          ],
        }),
        R.createElement(ErrorBanner, { error }),
        loading
          ? R.createElement(Loading, null)
          : R.createElement(
              R.Fragment,
              null,
              R.createElement(
                'div',
                { style: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(160px,1fr))', gap: 12 } },
                statCards.map(([label, value]) =>
                  R.createElement(
                    Card,
                    { key: label },
                    R.createElement(CardHeader, null, R.createElement(CardTitle, { style: { fontSize: 14, fontWeight: 500, opacity: 0.7 } }, label)),
                    R.createElement(CardContent, null, R.createElement('div', { style: { fontSize: 24, fontWeight: 700 } }, String(value ?? 0))),
                  ),
                ),
              ),
              R.createElement(
                Card,
                null,
                R.createElement(CardHeader, null, R.createElement(CardTitle, { style: { fontSize: 14, fontWeight: 500 } }, 'Recent Content')),
                R.createElement(
                  CardContent,
                  { style: { padding: 0 } },
                  recent.length === 0
                    ? R.createElement(Empty, { text: 'No content generated yet. Create an IdeaCloud to get started.' })
                    : R.createElement(
                        'table',
                        { style: { width: '100%', borderCollapse: 'collapse', fontSize: 14 } },
                        R.createElement(
                          'thead',
                          null,
                          R.createElement(
                            'tr',
                            { style: { textAlign: 'left', opacity: 0.7 } },
                            ['Keyword', 'Type', 'Status', 'Created'].map((h) =>
                              R.createElement('th', { key: h, style: { padding: 12, fontWeight: 500 } }, h),
                            ),
                          ),
                        ),
                        R.createElement(
                          'tbody',
                          null,
                          recent.map((c: any) =>
                            R.createElement(
                              'tr',
                              {
                                key: c.id,
                                'data-testid': 'hrizn-recent-row',
                                style: { cursor: 'pointer', borderTop: '1px solid rgba(128,128,128,0.2)' },
                                onClick: () => nav({ name: 'contentShow', id: c.id }),
                              },
                              R.createElement('td', { style: { padding: 12, fontWeight: 500 } }, c.ideacloud?.keyword ?? ARTICLE_TYPE_LABELS[c.article_type] ?? c.article_type),
                              R.createElement('td', { style: { padding: 12, opacity: 0.7 } }, ARTICLE_TYPE_LABELS[c.article_type] ?? c.article_type),
                              R.createElement('td', { style: { padding: 12 } }, R.createElement(StatusBadge, { status: c.status, variant: contentStatusVariant(c.status) })),
                              R.createElement('td', { style: { padding: 12, opacity: 0.7, whiteSpace: 'nowrap' } }, fmtDate(c.created_at)),
                            ),
                          ),
                        ),
                      ),
                ),
              ),
            ),
      );
    }

    // ── Ideaclouds list + create (GET /ideaclouds, POST /ideaclouds) ─────────────
    function Ideaclouds({ nav }: { nav: (v: View) => void }) {
      const [reload, setReload] = R.useState(0);
      const { data, error, loading } = useQuery<{ items: any[] }>('/ideaclouds', [reload]);
      const items = data?.items ?? [];

      const [formOpen, setFormOpen] = R.useState(false);
      const [keyword, setKeyword] = R.useState('');
      const [submitting, setSubmitting] = R.useState(false);
      const [formError, setFormError] = R.useState<string | null>(null);

      function submit() {
        const kw = keyword.trim();
        if (!kw || submitting) return;
        setSubmitting(true);
        setFormError(null);
        api
          .post('/ideaclouds', { keyword: kw })
          .then((r: any) => r.data.data)
          .then(() => {
            setKeyword('');
            setFormOpen(false);
            setReload((n) => n + 1);
          })
          .catch((e: any) => setFormError(e?.response?.data?.error ?? String(e)))
          .finally(() => setSubmitting(false));
      }

      return R.createElement(
        'div',
        { 'data-testid': 'hrizn-ideaclouds', style: { display: 'grid', gap: 16 } },
        R.createElement(PageHeader, {
          title: 'IdeaClouds',
          description: 'AI-powered keyword research for your dealership.',
          actions: [
            R.createElement(NavButton, { key: 'home', label: '← HRIZN', onClick: () => nav({ name: 'overview' }) }),
            R.createElement(Button, { key: 'new', size: 'sm', onClick: () => setFormOpen((v) => !v) }, 'New IdeaCloud'),
          ],
        }),
        R.createElement(ErrorBanner, { error }),
        formOpen &&
          R.createElement(
            Card,
            null,
            R.createElement(CardHeader, null, R.createElement(CardTitle, { style: { fontSize: 16 } }, 'New IdeaCloud')),
            R.createElement(
              CardContent,
              { style: { display: 'grid', gap: 8 } },
              R.createElement('label', { style: { fontSize: 14, fontWeight: 500 } }, 'Keyword *'),
              R.createElement('input', {
                value: keyword,
                onChange: (e: any) => setKeyword(e.target.value),
                onKeyDown: (e: any) => e.key === 'Enter' && submit(),
                placeholder: 'e.g. 2026 Chevrolet Silverado 1500 towing capacity',
                style: { padding: '8px 10px', border: '1px solid rgba(128,128,128,0.4)', borderRadius: 6, fontSize: 14 },
              }),
              R.createElement('p', { style: { fontSize: 12, opacity: 0.7 } }, 'Enter a keyword or topic to research. Be specific for best results.'),
              formError && R.createElement('p', { style: { fontSize: 13, color: 'crimson' } }, formError),
              R.createElement(
                'div',
                { style: { display: 'flex', gap: 8 } },
                R.createElement(Button, { variant: 'ghost', size: 'sm', onClick: () => setFormOpen(false) }, 'Cancel'),
                R.createElement(Button, { size: 'sm', onClick: submit, disabled: submitting || !keyword.trim() }, submitting ? 'Creating…' : 'Create IdeaCloud'),
              ),
            ),
          ),
        R.createElement(
          Card,
          null,
          R.createElement(
            CardContent,
            { style: { padding: 0 } },
            loading
              ? R.createElement(Loading, null)
              : items.length === 0
                ? R.createElement(Empty, { text: 'No IdeaClouds yet. Create your first keyword research to get started.' })
                : R.createElement(
                    'table',
                    { style: { width: '100%', borderCollapse: 'collapse', fontSize: 14 } },
                    R.createElement(
                      'thead',
                      null,
                      R.createElement(
                        'tr',
                        { style: { textAlign: 'left', opacity: 0.7 } },
                        ['Keyword', 'Status'].map((h) => R.createElement('th', { key: h, style: { padding: 12, fontWeight: 500 } }, h)),
                      ),
                    ),
                    R.createElement(
                      'tbody',
                      null,
                      items.map((ic: any) =>
                        R.createElement(
                          'tr',
                          {
                            key: ic.id,
                            'data-testid': 'hrizn-ideacloud-row',
                            style: { cursor: 'pointer', borderTop: '1px solid rgba(128,128,128,0.2)' },
                            onClick: () => nav({ name: 'ideacloudShow', id: ic.id }),
                          },
                          R.createElement('td', { style: { padding: 12, fontWeight: 500 } }, ic.keyword),
                          R.createElement('td', { style: { padding: 12 } }, R.createElement(StatusBadge, { status: ic.status, variant: ideacloudStatusVariant(ic.status) })),
                        ),
                      ),
                    ),
                  ),
          ),
        ),
      );
    }

    // ── IdeacloudShow (GET /ideaclouds/{id}) ─────────────────────────────────────
    function IdeacloudShow({ id, nav }: { id: string; nav: (v: View) => void }) {
      const { data, error, loading } = useQuery<any>('/ideaclouds/' + id);
      const status: string = data?.status ?? 'unknown';

      const statusMessage =
        status === 'complete'
          ? 'Research complete — ready to generate content.'
          : status === 'researching'
            ? 'HRIZN is researching this keyword…'
            : 'Research did not complete.';

      return R.createElement(
        'div',
        { 'data-testid': 'hrizn-ideacloud-show', style: { display: 'grid', gap: 16 } },
        R.createElement(
          Button,
          { variant: 'ghost', size: 'sm', onClick: () => nav({ name: 'ideaclouds' }), style: { width: 'fit-content', display: 'inline-flex', alignItems: 'center', gap: 8 } },
          R.createElement(IconArrowLeft, null),
          'Back to IdeaClouds',
        ),
        R.createElement(ErrorBanner, { error }),
        loading
          ? R.createElement(Loading, null)
          : R.createElement(
              R.Fragment,
              null,
              R.createElement(PageHeader, { title: data?.keyword ?? 'IdeaCloud' }),
              R.createElement(
                Card,
                null,
                R.createElement(CardHeader, null, R.createElement(CardTitle, { style: { fontSize: 14, fontWeight: 500 } }, 'Research Status')),
                R.createElement(
                  CardContent,
                  { style: { display: 'flex', alignItems: 'center', gap: 12 } },
                  R.createElement(StatusBadge, { status, variant: ideacloudStatusVariant(status) }),
                  R.createElement('span', { style: { fontSize: 14, opacity: 0.7 } }, statusMessage),
                ),
              ),
            ),
      );
    }

    // ── Content list (GET /content) — was Hrizn/Content ──────────────────────────
    function Content({ nav }: { nav: (v: View) => void }) {
      const [reload, setReload] = R.useState(0);
      const [genOpen, setGenOpen] = R.useState(false);
      const { data, error, loading } = useQuery<{ items: any[] }>('/content', [reload]);
      const items = data?.items ?? [];

      return R.createElement(
        'div',
        { 'data-testid': 'hrizn-content', style: { display: 'grid', gap: 16 } },
        R.createElement(PageHeader, {
          title: 'Content Library',
          description: 'All generated articles for this rooftop.',
          actions: [
            R.createElement(NavButton, { key: 'home', label: '← HRIZN', onClick: () => nav({ name: 'overview' }) }),
            R.createElement(Button, { key: 'gen', size: 'sm', onClick: () => setGenOpen((v) => !v) }, 'Generate Content'),
          ],
        }),
        R.createElement(ErrorBanner, { error }),
        genOpen &&
          R.createElement(GenerateForm, {
            onDone: () => {
              setGenOpen(false);
              setReload((n) => n + 1);
            },
          }),
        R.createElement(
          Card,
          null,
          R.createElement(
            CardContent,
            { style: { padding: 0 } },
            loading
              ? R.createElement(Loading, null)
              : items.length === 0
                ? R.createElement(Empty, { text: 'No content found. Generate content from an IdeaCloud to get started.' })
                : R.createElement(
                    'table',
                    { style: { width: '100%', borderCollapse: 'collapse', fontSize: 14 } },
                    R.createElement(
                      'thead',
                      null,
                      R.createElement(
                        'tr',
                        { style: { textAlign: 'left', opacity: 0.7 } },
                        ['Keyword', 'Type', 'Intent', 'Status'].map((h) => R.createElement('th', { key: h, style: { padding: 12, fontWeight: 500 } }, h)),
                      ),
                    ),
                    R.createElement(
                      'tbody',
                      null,
                      items.map((c: any) =>
                        R.createElement(
                          'tr',
                          {
                            key: c.id,
                            'data-testid': 'hrizn-content-row',
                            style: { cursor: 'pointer', borderTop: '1px solid rgba(128,128,128,0.2)' },
                            onClick: () => nav({ name: 'contentShow', id: c.id }),
                          },
                          R.createElement('td', { style: { padding: 12, fontWeight: 500 } }, c.ideacloud?.keyword ?? ARTICLE_TYPE_LABELS[c.article_type] ?? c.article_type),
                          R.createElement('td', { style: { padding: 12, opacity: 0.7 } }, ARTICLE_TYPE_LABELS[c.article_type] ?? c.article_type),
                          R.createElement('td', { style: { padding: 12, opacity: 0.7 } }, c.content_intent ? (INTENT_LABELS[c.content_intent] ?? c.content_intent) : '—'),
                          R.createElement(
                            'td',
                            { style: { padding: 12 } },
                            R.createElement(
                              'div',
                              { style: { display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' } },
                              R.createElement(StatusBadge, { status: c.status, variant: contentStatusVariant(c.status) }),
                              R.createElement(ReadyBadge, { status: c.status }),
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
          ),
        ),
      );
    }

    // ── ContentShow (GET /content/{id} + /content/{id}/html) ─────────────────────
    function ContentShow({ id, nav }: { id: string; nav: (v: View) => void }) {
      const { data: content, error, loading } = useQuery<any>('/content/' + id);
      const { data: htmlData } = useQuery<{ html: string }>('/content/' + id + '/html');

      const title = content ? ARTICLE_TYPE_LABELS[content.article_type] ?? content.article_type : 'Content';

      function Field({ label, value }: { label: string; value: any }) {
        return R.createElement(
          'div',
          null,
          R.createElement('p', { style: { fontSize: 12, opacity: 0.6 } }, label),
          R.createElement('div', { style: { marginTop: 2, fontSize: 14, fontWeight: 500 } }, value),
        );
      }

      return R.createElement(
        'div',
        { 'data-testid': 'hrizn-content-show', style: { display: 'grid', gap: 16 } },
        R.createElement(
          Button,
          { variant: 'ghost', size: 'sm', onClick: () => nav({ name: 'content' }), style: { width: 'fit-content', display: 'inline-flex', alignItems: 'center', gap: 8 } },
          R.createElement(IconArrowLeft, null),
          'Back to Content',
        ),
        R.createElement(ErrorBanner, { error }),
        loading
          ? R.createElement(Loading, null)
          : content &&
              R.createElement(
                R.Fragment,
                null,
                R.createElement(PageHeader, { title }),
                R.createElement(
                  'div',
                  { style: { display: 'grid', gap: 16, gridTemplateColumns: 'repeat(auto-fit,minmax(280px,1fr))' } },
                  R.createElement(
                    Card,
                    null,
                    R.createElement(CardHeader, null, R.createElement(CardTitle, { style: { fontSize: 14, fontWeight: 500 } }, 'Status')),
                    R.createElement(
                      CardContent,
                      { style: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 } },
                      R.createElement(Field, { label: 'Status', value: R.createElement(StatusBadge, { status: String(content.status ?? '—'), variant: contentStatusVariant(String(content.status ?? '')) }) }),
                      R.createElement(Field, { label: 'Article Type', value: ARTICLE_TYPE_LABELS[content.article_type] ?? content.article_type ?? '—' }),
                      R.createElement(Field, { label: 'Content Intent', value: content.content_intent ? (INTENT_LABELS[content.content_intent] ?? content.content_intent) : '—' }),
                      R.createElement(Field, { label: 'Compliance', value: content.compliance_status ? titleize(String(content.compliance_status)) : '—' }),
                    ),
                  ),
                  R.createElement(
                    Card,
                    null,
                    R.createElement(CardHeader, null, R.createElement(CardTitle, { style: { fontSize: 14, fontWeight: 500 } }, 'Article')),
                    R.createElement(
                      CardContent,
                      null,
                      htmlData?.html
                        ? R.createElement('div', {
                            style: { fontSize: 14, lineHeight: 1.5, maxHeight: 360, overflow: 'auto' },
                            dangerouslySetInnerHTML: { __html: htmlData.html },
                          })
                        : R.createElement('p', { style: { fontSize: 14, opacity: 0.7 } }, 'No rendered article available.'),
                    ),
                  ),
                ),
                Array.isArray(content.linkedVehicles) &&
                  content.linkedVehicles.length > 0 &&
                  R.createElement(
                    Card,
                    { 'data-testid': 'hrizn-linked-vehicles' },
                    R.createElement(CardHeader, null, R.createElement(CardTitle, { style: { fontSize: 14, fontWeight: 500 } }, 'Linked Vehicles')),
                    R.createElement(
                      CardContent,
                      { style: { display: 'flex', gap: 8, flexWrap: 'wrap' } },
                      content.linkedVehicles.map((v: any) =>
                        R.createElement(
                          Badge,
                          { key: v.vin, variant: 'secondary', 'data-testid': 'hrizn-linked-vehicle-chip' },
                          '🔗 ' + [v.year, v.make, v.model].filter((p: any) => p != null && p !== '').join(' '),
                        ),
                      ),
                    ),
                  ),
                content.error_message &&
                  R.createElement(
                    Card,
                    null,
                    R.createElement(CardHeader, null, R.createElement(CardTitle, { style: { fontSize: 14, fontWeight: 500, color: 'crimson' } }, 'Generation Error')),
                    R.createElement(CardContent, null, R.createElement('p', { style: { fontSize: 14 } }, content.error_message)),
                  ),
              ),
      );
    }

    // ── Settings (GET /settings + /settings/site; set/remove key; webhook) ───────
    function Settings({ nav }: { nav: (v: View) => void }) {
      const [reload, setReload] = R.useState(0);
      const { data, error, loading } = useQuery<any>('/settings', [reload]);

      const [apiKey, setApiKey] = R.useState('');
      const [busy, setBusy] = R.useState<string | null>(null);
      const [msg, setMsg] = R.useState<string | null>(null);
      const [actionError, setActionError] = R.useState<string | null>(null);

      function run(label: string, p: Promise<any>, success: string) {
        setBusy(label);
        setMsg(null);
        setActionError(null);
        p.then(() => {
          setMsg(success);
          setReload((n) => n + 1);
        })
          .catch((e: any) => setActionError(e?.response?.data?.error ?? String(e)))
          .finally(() => setBusy(null));
      }

      const hasKey = !!data?.hasApiKey;
      const hasWebhook = !!data?.webhookId;

      return R.createElement(
        'div',
        { 'data-testid': 'hrizn-settings', style: { display: 'grid', gap: 16 } },
        R.createElement(PageHeader, {
          title: 'HRIZN Settings',
          description: 'Connect your HRIZN account and manage webhooks.',
          actions: [R.createElement(NavButton, { key: 'home', label: '← HRIZN', onClick: () => nav({ name: 'overview' }) })],
        }),
        R.createElement(ErrorBanner, { error }),
        msg && R.createElement('div', { style: { color: 'seagreen', fontSize: 14 } }, msg),
        actionError && R.createElement('div', { style: { color: 'crimson', fontSize: 14 } }, actionError),
        loading
          ? R.createElement(Loading, null)
          : R.createElement(
              R.Fragment,
              null,
              R.createElement(
                Card,
                null,
                R.createElement(CardHeader, null, R.createElement(CardTitle, { style: { fontSize: 14, fontWeight: 500 } }, 'API Key')),
                R.createElement(
                  CardContent,
                  { style: { display: 'grid', gap: 8 } },
                  hasKey
                    ? R.createElement(
                        R.Fragment,
                        null,
                        R.createElement('p', { style: { fontSize: 14 } }, 'Connected: ', R.createElement('span', { style: { fontFamily: 'monospace' } }, data.apiKeyPreview ?? '••••')),
                        data.siteName && R.createElement('p', { style: { fontSize: 13, opacity: 0.7 } }, `Site: ${data.siteName}${data.siteDomain ? ' · ' + data.siteDomain : ''}`),
                        R.createElement(Button, { variant: 'destructive', size: 'sm', disabled: busy !== null, onClick: () => run('remove', api.delete('/settings/api-key'), 'API key removed.') }, busy === 'remove' ? 'Removing…' : 'Remove API Key'),
                      )
                    : R.createElement(
                        R.Fragment,
                        null,
                        R.createElement('input', {
                          value: apiKey,
                          onChange: (e: any) => setApiKey(e.target.value),
                          placeholder: 'hzk_…',
                          style: { padding: '8px 10px', border: '1px solid rgba(128,128,128,0.4)', borderRadius: 6, fontSize: 14, fontFamily: 'monospace' },
                        }),
                        R.createElement(
                          Button,
                          {
                            size: 'sm',
                            disabled: busy !== null || !apiKey.startsWith('hzk_'),
                            onClick: () => run('setKey', api.post('/settings/api-key', { apiKey }).then(() => setApiKey('')), 'API key saved.'),
                          },
                          busy === 'setKey' ? 'Saving…' : 'Save API Key',
                        ),
                      ),
                ),
              ),
              R.createElement(
                Card,
                null,
                R.createElement(CardHeader, null, R.createElement(CardTitle, { style: { fontSize: 14, fontWeight: 500 } }, 'Webhook')),
                R.createElement(
                  CardContent,
                  { style: { display: 'grid', gap: 8 } },
                  R.createElement(
                    'p',
                    { style: { fontSize: 14, opacity: 0.7 } },
                    hasWebhook ? `Registered${data.webhookRegisteredAt ? ' · ' + fmtDate(data.webhookRegisteredAt) : ''}` : 'No webhook registered.',
                  ),
                  R.createElement(
                    'div',
                    { style: { display: 'flex', gap: 8, flexWrap: 'wrap' } },
                    R.createElement(Button, { size: 'sm', disabled: busy !== null || !hasKey, onClick: () => run('register', api.post('/settings/webhook', {}), 'Webhook registered.') }, busy === 'register' ? 'Registering…' : 'Register Webhook'),
                    hasWebhook && R.createElement(Button, { variant: 'outline', size: 'sm', disabled: busy !== null, onClick: () => run('test', api.post('/settings/webhook/test', {}), 'Test delivery sent.') }, busy === 'test' ? 'Testing…' : 'Send Test'),
                  ),
                ),
              ),
            ),
      );
    }

    // ── View router (component state only; no URL router) ────────────────────────
    function App() {
      const [view, setView] = R.useState<View>({ name: 'overview' });
      const nav = (v: View) => setView(v);

      switch (view.name) {
        case 'ideaclouds':
          return R.createElement(Ideaclouds, { nav });
        case 'ideacloudShow':
          return R.createElement(IdeacloudShow, { id: view.id, nav });
        case 'content':
          return R.createElement(Content, { nav });
        case 'contentShow':
          return R.createElement(ContentShow, { id: view.id, nav });
        case 'settings':
          return R.createElement(Settings, { nav });
        case 'overview':
        default:
          return R.createElement(Overview, { nav });
      }
    }

    root.render(R.createElement(App));
    return () => root.unmount();
  },
};

export default plugin;
