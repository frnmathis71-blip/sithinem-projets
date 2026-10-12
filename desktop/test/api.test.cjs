const { test } = require('node:test');
const assert = require('node:assert/strict');
const { apiPath, apiBody, apiMethod } = require('../api.cjs');

test('requests stay inside the desktop API', () => {
  assert.equal(apiPath('/orders', { date: '2026-10-12', view: 'active', slot: '' }), '/api/desktop/orders?date=2026-10-12&view=active');
  assert.equal(apiPath('/orders/15/ticket'), '/api/desktop/orders/15/ticket');
  for (const path of ['/../admin', '//evil.example/x', 'https://evil.example', '/orders?x=1', '/Orders', '', '/orders/', '/orders#x']) assert.throws(() => apiPath(path), path);
  assert.throws(() => apiPath('/orders', { 'bad-name': 'x' }));
  assert.throws(() => apiPath('/orders', { date: { nested: true } }));
  assert.throws(() => apiMethod('PUT'));
  assert.equal(apiMethod('PATCH'), 'PATCH');
});

test('bodies are JSON or multipart with image files only', async () => {
  const json = await apiBody({ json: { status: 'completed' } });
  assert.equal(json.headers['Content-Type'], 'application/json');
  assert.equal(json.body, '{"status":"completed"}');
  const form = await apiBody({ form: [['name', 'Bobun'], ['available', true], ['image', { name: 'photo.png', type: 'image/png', data: new Uint8Array([137, 80, 78, 71]) }]] });
  assert.match(form.headers['Content-Type'], /^multipart\/form-data; boundary=/);
  const text = form.body.toString('latin1');
  assert.match(text, /name="available"\r\n\r\n1\r\n/);
  assert.match(text, /filename="photo.png"/);
  await assert.rejects(apiBody({ form: [['image', { name: 'x.exe', type: 'application/x-msdownload', data: new Uint8Array(1) }]] }));
  await assert.rejects(apiBody({ form: [['password', { name: 'x.png', type: 'image/png', data: new Uint8Array(1) }]] }));
  await assert.rejects(apiBody({ form: [['image', { name: 'big.png', type: 'image/png', data: new Uint8Array(20 * 1024 * 1024 + 1) }]] }));
  assert.equal((await apiBody(undefined)).body, undefined);
});
