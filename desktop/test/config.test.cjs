const { test } = require('node:test');
const assert = require('node:assert/strict');
const { validateSettings } = require('../config.cjs');
const valid = { siteUrl: 'https://restaurant.fr/', printer: 'Cuisine', width: 80, token: '', printing: false, startup: true };
test('normalizes HTTPS origin and rejects unsafe endpoints and printer names', () => {
  assert.equal(validateSettings(valid).siteUrl, 'https://restaurant.fr');
  for (const siteUrl of ['http://restaurant.fr', 'file:///C:/test', 'https://user:pass@restaurant.fr', 'https://restaurant.fr/path', 'https://restaurant.fr/?token=secret']) assert.throws(() => validateSettings({ ...valid, siteUrl }));
  assert.throws(() => validateSettings({ ...valid, printer: 'name\ncommand' }));
  assert.throws(() => validateSettings({ ...valid, width: 90 }));
  assert.throws(() => validateSettings({ ...valid, token: 'short' }));
});
