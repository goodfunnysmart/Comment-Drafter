// Run: node tests/client.mjs. Fake DOM and synthetic API responses; no live AI call.
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
class Element {
  constructor(value = '') { this.value = value; this.textContent = ''; this.hidden = true; this.disabled = false; this.attrs = {}; this.events = {}; this.classes = new Set(); this.classList = {add: x => this.classes.add(x), remove: x => this.classes.delete(x), contains: x => this.classes.has(x), toggle: (x,on) => { if (on ?? !this.classes.has(x)) this.classes.add(x); else this.classes.delete(x); }}; }
  addEventListener(name, fn) { (this.events[name] ||= []).push(fn); }
  async emit(name, event = {}) { for (const fn of this.events[name] || []) await fn({preventDefault(){}, ...event}); }
  setAttribute(k,v) { this.attrs[k] = v; }
  removeAttribute(k) { delete this.attrs[k]; }
  focus() { this.focused = true; }
  select() { this.selected = true; }
  setSelectionRange() {}
  append() {}
  remove() {}
  click() { this.clicked = true; }
}
const ids = ['builder','comment','generate','name','pronouns','observations','reframe','activities','adjustments','length','word-count','copy','save','generation-status','stale','contradictions','action-status','new-student','tweak','tweak-comment','undo-tweak','tweak-panel'];
const elements = Object.fromEntries(ids.map(id => [id, new Element()]));
elements.builder.reset = () => { for (const id of ['name','observations','reframe','activities','adjustments']) elements[id].value = ''; elements.pronouns.value = 'they'; elements.length.value = 'standard'; };
elements.builder.reportValidity = () => true;
const chips = ['natural-curiosity','consistent-attendance','attendance-routines'].map(id => {
  const el = new Element(); el.dataset = {id}; el.closest = () => ({dataset:{bank:id === 'attendance-routines' ? 'nextSteps' : 'strengths'}}); return el;
});
let fetchResolve, lastRequest, copied, savedBlob, anchor, confirmResult = true;
const context = vm.createContext({
  console, Intl, Date, Set, Object, String, JSON, Error, SyntaxError, TypeError, AbortController,
  setTimeout, clearTimeout,
  Blob: class { constructor(parts) { savedBlob = parts.join(''); } },
  URL: {createObjectURL:()=> 'blob:test', revokeObjectURL(){}},
  navigator:{clipboard:{writeText: async text => { copied = text; }}},
  fetch: (_url, options) => new Promise(resolve => { lastRequest = options; fetchResolve = resolve; }),
  document:{getElementById: id => elements[id], querySelectorAll: selector => selector === '.chip' ? chips : [], querySelector: selector => selector.startsWith('meta') ? {content:'test-csrf'} : chips[0], addEventListener(){}, createElement: () => (anchor = new Element()), body:new Element(), execCommand:() => true},
  window:{confirm:() => confirmResult, addEventListener(){}, location:{reload(){}}, innerWidth:1200}
});
vm.runInContext(readFileSync(new URL('../public/app.js', import.meta.url), 'utf8'), context);
const run = code => vm.runInContext(code, context);
assert.equal(elements.copy.disabled, true);
await elements.builder.emit('submit'); assert.match(elements['generation-status'].textContent, /first name/);
elements.name.value = 'Élodie O’Neil-Smith';
await elements.builder.emit('submit'); assert.match(elements['generation-status'].textContent, /at least one/);
await chips[0].emit('click'); assert.equal(chips[0].attrs['aria-pressed'], 'true');
await chips[0].emit('click'); assert.equal(chips[0].attrs['aria-pressed'], 'false');
await chips[0].emit('click');
elements.reframe.value = ' Rushes work ';
const pending = elements.builder.emit('submit');
assert.equal(elements.generate.disabled, true);
fetchResolve({ok:true,json:async()=>({ok:true,comment:'Synthetic test draft only.'})}); await pending;
assert.equal(elements.comment.value,'Synthetic test draft only.');
const sent = JSON.parse(lastRequest.body);
assert.equal(sent.reframe, 'Rushes work');
assert.deepEqual(sent.supports, []);
assert.deepEqual(sent.challenges, []);
assert.equal(sent.adjustments, '');
assert.equal('arrangements' in sent, false);
assert.equal('subject' in sent, false);
elements.comment.value = 'My latest edited comment.'; await elements.comment.emit('input');
await elements.copy.emit('click'); assert.equal(copied, elements.comment.value);
await elements.save.emit('click'); assert.equal(savedBlob, elements.comment.value); assert.match(anchor.download, /^Élodie O’Neil-Smith_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.txt$/);
assert.match(run("downloadFilename('CON', new Date(2026,9,22,14,35,9))"), /^Student_CON_2026-10-22_14-35-09.txt$/);
assert.equal(run("downloadFilename('../a:bc*', new Date(2026,9,22,14,35,9))"), 'abc_2026-10-22_14-35-09.txt');
assert.equal(elements['tweak-panel'].hidden, false);
const beforeTweak = lastRequest;
elements.tweak.value = '   ';
await elements['tweak-comment'].emit('click');
assert.equal(lastRequest, beforeTweak);
assert.match(elements['generation-status'].textContent, /changed/);
elements.tweak.value = ' Make it warmer ';
const tweaking = elements['tweak-comment'].emit('click');
assert.equal(elements['tweak-comment'].disabled, true);
assert.equal(elements.comment.readOnly, true);
const tweakSent = JSON.parse(lastRequest.body);
assert.equal(tweakSent.mode, 'tweak');
assert.equal(tweakSent.comment, 'My latest edited comment.');
assert.equal(tweakSent.tweak, 'Make it warmer');
assert.equal(tweakSent.name, 'Élodie O’Neil-Smith');
assert.deepEqual(tweakSent.supports, []);
fetchResolve({ok:true,json:async()=>({ok:true,comment:'Tweaked synthetic draft.'})});
await tweaking;
assert.equal(elements.comment.value, 'Tweaked synthetic draft.');
assert.equal(elements.tweak.value, '');
assert.equal(elements['undo-tweak'].hidden, false);
await elements['undo-tweak'].emit('click');
assert.equal(elements.comment.value, 'My latest edited comment.');
assert.equal(elements['undo-tweak'].hidden, true);
elements.tweak.value = 'Shorter please';
const failedTweak = elements['tweak-comment'].emit('click');
fetchResolve({ok:false,json:async()=>({ok:false,error:'The draft did not use the student’s first name exactly three times, so it was not kept. Your existing comment is unchanged. Please try again.'})});
await failedTweak;
assert.equal(elements.comment.value, 'My latest edited comment.');
assert.equal(elements.tweak.value, 'Shorter please');
assert.match(elements['generation-status'].textContent, /exactly three times/);
confirmResult = false;
await elements.builder.emit('submit'); assert.equal(elements.generate.disabled, false); assert.equal(elements.comment.value,'My latest edited comment.');
confirmResult = true;
const failed = elements.builder.emit('submit'); fetchResolve({ok:false,json:async()=>({ok:false,error:'Quota test error'})}); await failed;
assert.equal(elements.comment.value,'My latest edited comment.'); assert.match(elements['generation-status'].textContent, /Quota/);
const noticed = elements.builder.emit('submit');
fetchResolve({ok:true,json:async()=>({ok:true,comment:'Short draft.',notice:'Your draft is ready. Please review the length before you use it.'})});
await noticed;
assert.equal(elements.comment.value, 'Short draft.');
assert.match(elements['generation-status'].textContent, /review the length/);
assert.equal(elements['generation-status'].classes.has('error'), false);
elements.observations.value = 'Joined the discussion.'; await elements.builder.emit('input'); assert.equal(elements.stale.hidden, false);
await chips[1].emit('click'); await chips[2].emit('click'); assert.equal(elements.contradictions.hidden, false);
const old = elements.builder.emit('submit'); const oldResolve = fetchResolve;
elements.reframe.value = 'Calls out';
elements.tweak.value = 'leftover';
await elements['new-student'].emit('click'); assert.equal(elements.comment.value, ''); assert.equal(elements.name.value, ''); assert.equal(elements.reframe.value, ''); assert.equal(elements.tweak.value, ''); assert.equal(elements['undo-tweak'].hidden, true); assert.equal(elements.name.focused, true);
oldResolve({ok:true,json:async()=>({ok:true,comment:'Old response must not appear'})}); await old;
assert.equal(elements.comment.value,''); assert.equal(elements.generate.disabled,false);
const placed = run(`(field => (insertTranscript(field, 'there'), field.value))({value:'Hello', selectionStart:5, selectionEnd:5, maxLength:200})`);
assert.equal(placed, 'Hello there');
const between = run(`(field => (insertTranscript(field, 'kind'), field.value))({value:'Hello world', selectionStart:5, selectionEnd:5, maxLength:200})`);
assert.equal(between, 'Hello kind world');
const clipped = run(`(field => insertTranscript(field, 'there now'))({value:'Hello', selectionStart:5, selectionEnd:5, maxLength:12})`);
assert.equal(clipped, 'clipped');
assert.equal(run(`(field => (insertTranscript(field, 'there now'), field.value))({value:'Hello', selectionStart:5, selectionEnd:5, maxLength:12})`).length, 12);
assert.equal(run(`(field => insertTranscript(field, 'more'))({value:'Hello', selectionStart:5, selectionEnd:5, maxLength:5})`), 'full');
assert.equal(run(`insertTranscript({value:'Hello', selectionStart:5, selectionEnd:5, maxLength:20}, '   ')`), 'empty');
console.log('PASS: selection toggles, validation, synthetic success/error, manual edit confirmation, copy/save edited text, tweak of the current comment, undo, safe local filenames, stale/conflict notices, clear/late-response protection, dictation insert. No live AI call.');
