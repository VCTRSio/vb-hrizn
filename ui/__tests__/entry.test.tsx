// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach } from 'vitest';
import * as React from 'react';
import * as ReactDOMClient from 'react-dom/client';

// The vendored kit builds its client with axios.create() and reads the {traceId,data,status}
// envelope off response.data. Mock axios so createApiClient('/api/v1/hrizn') returns a stub
// whose .get resolves enveloped payloads — no real network, session-authed shape preserved.
const envelope = (data: unknown) => ({ data: { traceId: 't-1', status: 'success', data } });

const get = vi.fn(async (path: string) => {
  if (path === '/overview')
    return envelope({
      stats: { totalContent: 3, contentThisMonth: 1, ideacloudCount: 2, inProgressCount: 1 },
      recentContent: [
        { id: 'c1', article_type: 'basic', content_intent: 'general', status: 'complete', created_at: '2026-07-14T00:00:00Z', ideacloud: { id: 'i1', keyword: 'Silverado towing' } },
      ],
    });
  if (path === '/ideaclouds')
    return envelope({ items: [{ id: 'i1', keyword: 'Silverado towing', status: 'complete', localId: 'l1' }], pagination: {}, source: 'local' });
  if (path.startsWith('/ideaclouds/'))
    return envelope({ id: 'i1', keyword: 'Silverado towing', status: 'complete' });
  if (path === '/content')
    return envelope({ items: [{ id: 'c1', article_type: 'basic', content_intent: 'general', status: 'complete', localId: 'l1' }], pagination: {}, source: 'local' });
  if (path.startsWith('/vehicles/search'))
    return envelope([{ vin: '1HGCM82633A004352', year: 2022, make: 'Honda', model: 'Accord', trim: 'EX' }]);
  if (path === '/content/c1/html') return envelope({ html: '<p>Article body</p>' });
  if (path === '/content/c1')
    return envelope({
      id: 'c1',
      article_type: 'modellanding',
      content_intent: 'general',
      status: 'complete',
      linkedVehicles: [{ vin: '1HGCM82633A004352', year: 2022, make: 'Honda', model: 'Accord', trim: 'EX' }],
    });
  if (path === '/settings')
    return envelope({ hasApiKey: false, apiKeyPreview: null, webhookId: null, siteName: null });
  return envelope({});
});

vi.mock('axios', () => {
  const client = { get, post: vi.fn(async () => envelope(null)), delete: vi.fn() };
  return { default: { create: () => client } };
});

// Import AFTER the mock is registered so the vendored kit picks up the stub.
const plugin = (await import('../entry')).default;

const host = {
  React,
  ReactDOM: ReactDOMClient,
  ui: {
    Card: ({ children }: any) => React.createElement('div', null, children),
    CardHeader: ({ children }: any) => React.createElement('div', null, children),
    CardContent: ({ children }: any) => React.createElement('div', null, children),
    CardTitle: ({ children }: any) => React.createElement('div', null, children),
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Button: ({ children, onClick, disabled }: any) => React.createElement('button', { onClick, disabled }, children),
  },
};

const flush = () => new Promise((r) => setTimeout(r, 0));

const click = (el: Element) => el.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

// Drive a controlled <select> the way React expects: use the native value setter
// then dispatch a bubbling 'change' so React's synthetic onChange fires.
function setSelect(select: HTMLSelectElement, value: string) {
  const setter = Object.getOwnPropertyDescriptor(window.HTMLSelectElement.prototype, 'value')!.set!;
  setter.call(select, value);
  select.dispatchEvent(new window.Event('change', { bubbles: true }));
}

async function waitFor<T>(check: () => T | null | undefined, tries = 50): Promise<T> {
  for (let i = 0; i < tries; i++) {
    const v = check();
    if (v) return v;
    await flush();
  }
  throw new Error('waitFor: condition never met');
}

beforeEach(() => {
  get.mockClear();
});

describe('hrizn esm entry', () => {
  it('exports a mount function that renders and returns cleanup', async () => {
    const el = document.createElement('div');
    expect(typeof plugin.mount).toBe('function');
    const cleanup = plugin.mount(el, host as any, { slug: 'vb-hrizn', route: '' });
    await flush();
    expect(typeof cleanup).toBe('function');
    cleanup?.();
  });

  it('renders the overview with stats from the mocked /overview envelope', async () => {
    const el = document.createElement('div');
    const cleanup = plugin.mount(el, host as any, { slug: 'vb-hrizn', route: '' });

    await waitFor(() => (el.textContent?.includes('Total Content') ? true : null));
    expect(el.querySelector('[data-testid="hrizn-overview"]')).not.toBeNull();
    expect(el.textContent).toContain('HRIZN');
    expect(el.textContent).toContain('Silverado towing');
    cleanup?.();
  });

  it('navigates to the IdeaClouds list and renders a row', async () => {
    const el = document.createElement('div');
    const cleanup = plugin.mount(el, host as any, { slug: 'vb-hrizn', route: '' });

    const btn = await waitFor(() =>
      Array.from(el.querySelectorAll('button')).find((b) => b.textContent === 'IdeaClouds'),
    );
    (btn as HTMLButtonElement).dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

    await waitFor(() => el.querySelector('[data-testid="hrizn-ideacloud-row"]'));
    expect(el.querySelector('[data-testid="hrizn-ideaclouds"]')).not.toBeNull();
    expect(el.textContent).toContain('Silverado towing');
    cleanup?.();
  });

  it('shows a "Ready to publish" badge for a complete content row', async () => {
    const el = document.createElement('div');
    const cleanup = plugin.mount(el, host as any, { slug: 'vb-hrizn', route: '' });

    const btn = await waitFor(() =>
      Array.from(el.querySelectorAll('button')).find((b) => b.textContent === 'Content Library'),
    );
    click(btn as HTMLButtonElement);

    // The host test-mock Badge forwards only children (drops data-testid), so
    // assert on the rendered pill text.
    await waitFor(() => (el.textContent?.includes('Ready to publish') ? true : null));
    expect(el.textContent).toContain('Ready to publish');
    cleanup?.();
  });

  it('renders the vehicle picker for modellanding but not for basic', async () => {
    const el = document.createElement('div');
    const cleanup = plugin.mount(el, host as any, { slug: 'vb-hrizn', route: '' });

    const nav = await waitFor(() =>
      Array.from(el.querySelectorAll('button')).find((b) => b.textContent === 'Content Library'),
    );
    click(nav as HTMLButtonElement);

    const gen = await waitFor(() =>
      Array.from(el.querySelectorAll('button')).find((b) => b.textContent === 'Generate Content'),
    );
    click(gen as HTMLButtonElement);

    // The article-type <select> is a real element (real data-testid); default is
    // 'basic' → no vehicle field.
    const select = (await waitFor(() => el.querySelector('[data-testid="hrizn-article-type"]'))) as HTMLSelectElement;
    expect(el.querySelector('[data-testid="hrizn-vehicle-field"]')).toBeNull();

    // Switching to a vehicle-specific type reveals the picker.
    setSelect(select, 'modellanding');
    await waitFor(() => el.querySelector('[data-testid="hrizn-vehicle-field"]'));
    expect(el.querySelector('[data-testid="hrizn-vehicle-field"]')).not.toBeNull();
    cleanup?.();
  });

  it('renders a linked-vehicle chip on the content show view when present', async () => {
    const el = document.createElement('div');
    const cleanup = plugin.mount(el, host as any, { slug: 'vb-hrizn', route: '' });

    const nav = await waitFor(() =>
      Array.from(el.querySelectorAll('button')).find((b) => b.textContent === 'Content Library'),
    );
    click(nav as HTMLButtonElement);

    const row = await waitFor(() => el.querySelector('[data-testid="hrizn-content-row"]'));
    click(row as Element);

    // Chip is a Badge (mock drops data-testid) → assert on its rendered label.
    await waitFor(() => (el.textContent?.includes('🔗 2022 Honda Accord') ? true : null));
    expect(el.textContent).toContain('🔗 2022 Honda Accord');
    cleanup?.();
  });

  it('cleanup unmounts the tree', async () => {
    const el = document.createElement('div');
    const cleanup = plugin.mount(el, host as any, { slug: 'vb-hrizn', route: '' });
    await waitFor(() => (el.textContent?.includes('HRIZN') ? true : null));
    cleanup?.();
    await flush();
    expect(el.textContent).toBe('');
  });
});
