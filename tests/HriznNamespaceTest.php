<?php

use App\Models\PluginNamespace;
use Vctrs\Plugins\VbHrizn\Support\HriznNamespace;

require_once __DIR__.'/hz_bootstrap.php';

it('patch persists an ENCRYPTED blob and get decrypts it', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_secret', 'siteName' => 'Acme']);

    $data = HriznNamespace::get('rooftop', PLUGIN_TEST_TENANT);
    expect($data['apiKey'])->toBe('hzk_secret')->and($data['siteName'])->toBe('Acme');

    // Raw DB column must NOT contain the plaintext key (QOL: encrypted at rest).
    $raw = PluginNamespace::query()->where('namespace', 'vb-hrizn:'.PLUGIN_TEST_TENANT)->value('data_json');
    $rawJson = is_string($raw) ? $raw : json_encode($raw);
    expect($rawJson)->not->toContain('hzk_secret');
});

it('patch merges over prior data without dropping keys', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_a', 'webhookId' => 'wh_1']);
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['siteName' => 'Acme']);

    $data = HriznNamespace::get('rooftop', PLUGIN_TEST_TENANT);
    expect($data['apiKey'])->toBe('hzk_a')->and($data['webhookId'])->toBe('wh_1')->and($data['siteName'])->toBe('Acme');
});

it('clear wipes the secret blob', function () {
    HriznNamespace::patch('rooftop', PLUGIN_TEST_TENANT, ['apiKey' => 'hzk_a']);
    HriznNamespace::clear('rooftop', PLUGIN_TEST_TENANT);

    expect(HriznNamespace::get('rooftop', PLUGIN_TEST_TENANT))->toBe([]);
});

it('get on a missing namespace returns an empty array', function () {
    expect(HriznNamespace::get('rooftop', PLUGIN_TEST_TENANT))->toBe([]);
});
