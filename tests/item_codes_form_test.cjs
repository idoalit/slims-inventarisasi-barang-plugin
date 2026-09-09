const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../index.php'), 'utf8');
const script = source.match(/<script>\n(window\.inventoryMakeCode[\s\S]*?)<\/script>/)[1];
const context = {window: {}, FormData, Error, SyntaxError};
vm.createContext(context);
vm.runInContext(script, context);
function setup(code = '', room = '1') {
  const error = {textContent: ''};
  const submit = {disabled: false};
  const form = {dataset: {}, elements: {item_code: {value: code}, location_id: {value: room}, csrf_token: {value: 'csrf'}, code_form_token: {value: 'token'}}, querySelector: s => s === 'button[type="submit"]' ? submit : error};
  const button = {form, dataset: {url: '/plugin'}, disabled: false};
  return {form, button, error, submit};
}
(async () => {
  let calls = 0;
  context.fetch = async () => {calls++; return {json: async () => ({ok: true, code: 'P01-INV-000001'})};};
  context.window.confirm = () => false;
  let ui = setup('', '');
  await context.window.inventoryMakeCode(ui.button);
  assert.equal(calls, 0); assert.match(ui.error.textContent, /Pilih/);
  ui = setup('MANUAL');
  await context.window.inventoryMakeCode(ui.button);
  assert.equal(calls, 0); assert.equal(ui.form.elements.item_code.value, 'MANUAL');
  context.window.confirm = () => true;
  let release;
  context.fetch = async (url, request) => {
    calls++;
    assert.equal(request.body.get('location_id'), '1');
    assert.equal(request.body.get('csrf_token'), 'csrf');
    assert.equal(request.body.get('code_form_token'), 'token');
    assert.equal(request.body.get('form_action'), 'reserve_item_code');
    await new Promise(resolve => { release = resolve; });
    return {json: async () => ({ok: true, code: 'P01-INV-000001'})};
  };
  const pending = context.window.inventoryMakeCode(ui.button);
  assert.equal(ui.button.disabled, true); assert.equal(ui.submit.disabled, true);
  assert.equal(ui.form.elements.item_code.readOnly, true);
  await context.window.inventoryMakeCode(ui.button);
  assert.equal(calls, 1);
  let prevented = 0;
  assert.equal(context.window.inventorySaveWithPhotos({preventDefault: () => prevented++, stopImmediatePropagation() {}}, ui.form), false);
  assert.equal(prevented, 1); assert.equal(calls, 1);
  release(); await pending;
  assert.equal(ui.form.elements.item_code.value, 'P01-INV-000001');
  assert.equal(ui.button.disabled, false); assert.equal(ui.submit.disabled, false);
  for (const result of [() => {throw new Error('Network failure');}, () => ({json: async () => ({ok: false, message: 'Lokasi tidak valid'})}), () => ({json: async () => {throw new SyntaxError('Login page');}})]) {
    context.fetch = async () => result();
    await context.window.inventoryMakeCode(ui.button);
    assert.equal(ui.form.elements.item_code.value, 'P01-INV-000001');
    assert.ok(ui.error.textContent); assert.equal(ui.button.disabled, false);
  }
  console.log('ok   location selection, replacement confirmation, request tokens, pending controls, duplicate clicks, save guard, exact code and failure preservation');
})().catch(error => {console.error(error); process.exitCode = 1;});
