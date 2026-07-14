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
  if (path === '/settings')
    return envelope({ hasApiKey: false, apiKeyPreview: null, webhookId: null, siteName: null });
  return envelope({});
});

vi.mock('axios', () => {
  const client = { get, post: vi.fn(), delete: vi.fn() };
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

  it('cleanup unmounts the tree', async () => {
    const el = document.createElement('div');
    const cleanup = plugin.mount(el, host as any, { slug: 'vb-hrizn', route: '' });
    await waitFor(() => (el.textContent?.includes('HRIZN') ? true : null));
    cleanup?.();
    await flush();
    expect(el.textContent).toBe('');
  });
});
