<?php

use Illuminate\Support\Facades\Http;
use Vctrs\Plugins\VbHrizn\Services\HriznApiException;
use Vctrs\Plugins\VbHrizn\Services\HriznClient;
use Vctrs\Plugins\VbHrizn\Support\HriznWebhookSignature;

require_once __DIR__.'/hz_bootstrap.php';

const HRIZN_BASE = 'https://api.app.hrizn.io/v1/public';

it('HriznApiException exposes status, code and requestId', function () {
    $e = new HriznApiException(429, 'rate_limited', 'Too many requests', 'req_abc');

    expect($e->status())->toBe(429)
        ->and($e->code())->toBe('rate_limited')
        ->and($e->getMessage())->toBe('Too many requests')
        ->and($e->requestId())->toBe('req_abc');
});

it('getSite GETs /site with the X-API-Key header and returns the data envelope', function () {
    Http::fake([
        'api.app.hrizn.io/v1/public/site' => Http::response([
            'data' => [
                'id' => 'site_1', 'name' => 'Acme Ford', 'vertical' => 'automotive',
                'domain' => 'acmeford.com', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701',
                'created_at' => '2026-01-01T00:00:00Z', 'updated_at' => '2026-01-01T00:00:00Z',
            ],
        ]),
    ]);

    $site = (new HriznClient('hzk_test_key'))->getSite();

    expect($site['id'])->toBe('site_1')
        ->and($site['name'])->toBe('Acme Ford')
        ->and($site['domain'])->toBe('acmeford.com');

    Http::assertSent(fn ($r) => $r->url() === HRIZN_BASE.'/site'
        && $r->method() === 'GET'
        && $r->hasHeader('X-API-Key', 'hzk_test_key'));
});

it('throws HriznApiException with the API error code and message on a 4xx', function () {
    Http::fake([
        'api.app.hrizn.io/v1/public/site' => Http::response([
            'error' => ['code' => 'unauthorized', 'message' => 'Bad API key'],
            'request_id' => 'req_9',
        ], 401),
    ]);

    (new HriznClient('hzk_bad'))->getSite();
})->throws(HriznApiException::class, 'Bad API key');

it('createIdeaCloud POSTs the keyword and returns the created record', function () {
    Http::fake([
        'api.app.hrizn.io/v1/public/ideaclouds' => Http::response([
            'data' => ['id' => 'ic_1', 'status' => 'researching', 'keyword' => 'brakes'],
        ], 202),
    ]);

    $ic = (new HriznClient('hzk_ok'))->createIdeaCloud('brakes');

    expect($ic['id'])->toBe('ic_1')->and($ic['status'])->toBe('researching');
    Http::assertSent(fn ($r) => $r->method() === 'POST'
        && $r->url() === HRIZN_BASE.'/ideaclouds'
        && $r->data() === ['keyword' => 'brakes']);
});

it('listContent forwards article_type/content_intent as query params', function () {
    Http::fake([
        'api.app.hrizn.io/v1/public/content?*' => Http::response([
            'data' => [['id' => 'c1', 'status' => 'complete', 'article_type' => 'basic']],
            'pagination' => ['has_more' => false, 'next_cursor' => null, 'total_count' => 1],
        ]),
    ]);

    $res = (new HriznClient('hzk_ok'))->listContent(['limit' => 10, 'article_type' => 'qa']);

    expect($res['data'])->toHaveCount(1)->and($res['pagination']['total_count'])->toBe(1);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'article_type=qa') && str_contains($r->url(), 'limit=10'));
});

it('generateContentBatch reads data.items and only sends auto_* flags when true', function () {
    Http::fake([
        'api.app.hrizn.io/v1/public/content/batch' => Http::response([
            'data' => ['items' => [['id' => 'c1'], ['id' => 'c2']], 'message' => 'queued'],
        ]),
    ]);

    $items = (new HriznClient('hzk_ok'))->generateContentBatch([
        ['ideacloud_id' => 'ic1', 'article_type' => 'basic', 'auto_compliance' => false],
        ['ideacloud_id' => 'ic2', 'article_type' => 'qa', 'auto_compliance' => true],
    ]);

    expect($items)->toHaveCount(2)->and($items[0]['id'])->toBe('c1');
    Http::assertSent(function ($r) {
        $sent = $r->data()['items'];

        return ! array_key_exists('auto_compliance', $sent[0])  // false → omitted
            && ($sent[1]['auto_compliance'] ?? null) === true;   // true → sent
    });
});

it('getContentHtml requests text/html and returns the raw body', function () {
    Http::fake(['api.app.hrizn.io/v1/public/content/c1/html' => Http::response('<h1>Hi</h1>', 200)]);

    expect((new HriznClient('hzk_ok'))->getContentHtml('c1'))->toBe('<h1>Hi</h1>');
    Http::assertSent(fn ($r) => $r->hasHeader('Accept', 'text/html'));
});

it('verifies a valid sha256 HMAC signature and rejects a tampered one', function () {
    $secret = 'whsec_test';
    $body = '{"type":"content.completed","data":{"article_id":"c1"}}';
    $good = 'sha256='.hash_hmac('sha256', $body, $secret);

    expect(HriznWebhookSignature::verify($body, $good, $secret))->toBeTrue()
        ->and(HriznWebhookSignature::verify($body, 'sha256=deadbeef', $secret))->toBeFalse()
        ->and(HriznWebhookSignature::verify($body, 'nope', $secret))->toBeFalse();
});
