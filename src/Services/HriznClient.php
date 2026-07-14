<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn\Services;

use App\Support\OutboundUrl;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Typed wrapper over the Hrizn Public API.
 *
 * Base:  https://api.app.hrizn.io/v1/public
 * Auth:  X-API-Key: hzk_...
 *
 * Ports packages/plugins/hrizn/client.ts. Every outbound URL passes through
 * App\Support\OutboundUrl::assertSafe() (SSRF guard). API errors ({error:{code,
 * message}}) are re-thrown as HriznApiException so the controller layer maps
 * status → HTTP response codes (see callHrizn()).
 */
final class HriznClient
{
    private const BASE_URL = 'https://api.app.hrizn.io/v1/public';

    private const TIMEOUT_SECONDS = 30;

    public function __construct(private readonly string $apiKey) {}

    // ── Site ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function getSite(): array
    {
        return $this->dataOf($this->request('GET', '/site'));
    }

    // ── IdeaClouds ────────────────────────────────────────────────────────

    /**
     * @param  array{limit?: int, cursor?: string, status?: string, keyword?: string}  $params
     * @return array{data: list<array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function listIdeaClouds(array $params = []): array
    {
        return $this->listOf($this->request('GET', '/ideaclouds'.$this->qs($params)));
    }

    /** @return array<string, mixed> */
    public function createIdeaCloud(string $keyword, ?string $categoryId = null): array
    {
        $body = ['keyword' => $keyword];
        if ($categoryId !== null) {
            $body['category_id'] = $categoryId;
        }

        return $this->dataOf($this->request('POST', '/ideaclouds', $body));
    }

    /** @return array<string, mixed> */
    public function getIdeaCloud(string $id): array
    {
        return $this->dataOf($this->request('GET', '/ideaclouds/'.rawurlencode($id)));
    }

    // ── Content ───────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function getContent(string $id): array
    {
        return $this->dataOf($this->request('GET', '/content/'.rawurlencode($id)));
    }

    /**
     * @param  array{limit?: int, cursor?: string, article_type?: string, content_intent?: string}  $params
     * @return array{data: list<array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function listContent(array $params = []): array
    {
        return $this->listOf($this->request('GET', '/content'.$this->qs($params)));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function generateContent(array $params): array
    {
        // API defaults are false — only send auto_* flags when explicitly true (client.ts:180).
        $body = ['ideacloud_id' => $params['ideacloud_id'], 'article_type' => $params['article_type']];
        foreach (['content_intent', 'title', 'language', 'content_length'] as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $body[$key] = $params[$key];
            }
        }
        if (! empty($params['auto_compliance'])) {
            $body['auto_compliance'] = true;
        }
        if (! empty($params['auto_content_tools'])) {
            $body['auto_content_tools'] = true;
        }

        return $this->dataOf($this->request('POST', '/content', $body));
    }

    /**
     * Batch content generation — up to 10 items. API returns { data: { items } }.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function generateContentBatch(array $items): array
    {
        $payload = array_map(static function (array $i): array {
            $item = ['ideacloud_id' => $i['ideacloud_id'], 'article_type' => $i['article_type']];
            if (isset($i['content_intent'])) {
                $item['content_intent'] = $i['content_intent'];
            }
            if (! empty($i['auto_compliance'])) {
                $item['auto_compliance'] = true;
            }
            if (! empty($i['auto_content_tools'])) {
                $item['auto_content_tools'] = true;
            }

            return $item;
        }, $items);

        $data = $this->dataOf($this->request('POST', '/content/batch', ['items' => $payload]));
        $out = $data['items'] ?? [];

        return is_array($out) ? array_values(array_filter($out, 'is_array')) : [];
    }

    /** Raw HTML for a completed content item (Accept: text/html). */
    public function getContentHtml(string $id): string
    {
        $url = OutboundUrl::assertSafe(self::BASE_URL.'/content/'.rawurlencode($id).'/html');
        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->withHeaders(['X-API-Key' => $this->apiKey, 'Accept' => 'text/html'])
            ->get($url);

        if (! $response->successful()) {
            throw new HriznApiException($response->status(), 'fetch_error', 'Failed to fetch content HTML');
        }

        return $response->body();
    }

    /** @return array<string, mixed> */
    public function getContentComponents(string $id): array
    {
        return $this->dataOf($this->request('GET', '/content/'.rawurlencode($id).'/components'));
    }

    // ── Content Intelligence ──────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function getIntelligenceSummary(): array
    {
        return $this->dataOf($this->request('GET', '/content-intelligence/summary'));
    }

    /**
     * @param  array{limit?: int, cursor?: string}  $params
     * @return array{data: list<array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function listIntelligenceRecommendations(array $params = []): array
    {
        return $this->listOf($this->request('GET', '/content-intelligence'.$this->qs($params)));
    }

    // ── Webhooks ──────────────────────────────────────────────────────────

    /**
     * Register a webhook. The returned `secret` is shown ONCE — store immediately.
     *
     * @param  array{url: string, events: list<string>}  $params
     * @return array<string, mixed>
     */
    public function createWebhook(array $params): array
    {
        return $this->dataOf($this->request('POST', '/webhooks', [
            'url' => $params['url'],
            'events' => $params['events'],
        ]));
    }

    public function deleteWebhook(string $id): void
    {
        $this->request('DELETE', '/webhooks/'.rawurlencode($id));
    }

    /** @return array<string, mixed> */
    public function testWebhook(string $id): array
    {
        return $this->dataOf($this->request('POST', '/webhooks/'.rawurlencode($id).'/test'));
    }

    // ── Core fetch ────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $url = OutboundUrl::assertSafe(self::BASE_URL.$path);

        $pending = Http::timeout(self::TIMEOUT_SECONDS)->withHeaders([
            'X-API-Key' => $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]);

        $response = match ($method) {
            'GET' => $pending->get($url),
            'POST' => $pending->post($url, $body ?? []),
            'PATCH' => $pending->patch($url, $body ?? []),
            'DELETE' => $pending->delete($url),
            default => throw new HriznApiException(0, 'bad_method', "Unsupported method {$method}"),
        };

        return $this->decode($response);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        if ($response->status() === 204) {
            return [];
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new HriznApiException($response->status(), 'parse_error', 'Failed to parse API response');
        }

        if (! $response->successful()) {
            $err = is_array($json['error'] ?? null) ? $json['error'] : [];
            throw new HriznApiException(
                $response->status(),
                (string) ($err['code'] ?? $json['code'] ?? 'unknown'),
                (string) ($err['message'] ?? $json['message'] ?? 'HTTP '.$response->status()),
                isset($json['request_id']) ? (string) $json['request_id'] : null,
            );
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private function dataOf(array $json): array
    {
        $data = $json['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{data: list<array<string, mixed>>, pagination: array<string, mixed>}
     */
    private function listOf(array $json): array
    {
        $data = is_array($json['data'] ?? null) ? array_values(array_filter($json['data'], 'is_array')) : [];
        $pagination = is_array($json['pagination'] ?? null) ? $json['pagination'] : [];

        return ['data' => $data, 'pagination' => $pagination];
    }

    /**
     * Build a `?a=b&c=d` string, dropping null/empty values.
     *
     * @param  array<string, mixed>  $params
     */
    private function qs(array $params): string
    {
        $clean = [];
        foreach ($params as $key => $value) {
            if ($value !== null && $value !== '') {
                $clean[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }

        return $clean === [] ? '' : '?'.http_build_query($clean, '', '&', PHP_QUERY_RFC3986);
    }
}
