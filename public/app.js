'use strict';
const $ = id => document.getElementById(id);
const form = $('builder'), editor = $('comment'), generate = $('generate');
const chosen = {strengths: new Set(), nextSteps: new Set(), supports: new Set()};
let controller = null, requestVersion = 0, generatedSnapshot = null, lastGenerated = '', busy = false;
const fields = ['name', 'pronouns', 'subject', 'observations', 'reframe', 'activities', 'length'];
function inputs() {
  return {...Object.fromEntries(fields.map(id => [id, $(id).value.trim()])),
    strengths: [...chosen.strengths].sort(), nextSteps: [...chosen.nextSteps].sort(), supports: [...chosen.supports].sort()};
}
function updateCounts() {
  document.querySelectorAll('[data-category]').forEach(group => {
    const count = group.querySelectorAll('.chip[aria-pressed="true"]').length;
    const el = group.querySelector('.count');
    if (el) el.textContent = `${count} selected`;
  });
}
const snapshot = () => JSON.stringify(inputs());
function setStatus(message, error = false) {
  $('generation-status').textContent = message;
  $('generation-status').classList.toggle('error', error);
}
function updateEditor() {
  const text = editor.value.trim();
  const count = typeof Intl.Segmenter === 'function'
    ? [...new Intl.Segmenter('en-AU', {granularity: 'word'}).segment(text)].filter(x => x.isWordLike).length
    : (text.match(/\S+/gu) || []).length;
  $('word-count').textContent = `${count} ${count === 1 ? 'word' : 'words'}`;
  $('copy').disabled = $('save').disabled = !text;
  generate.textContent = busy ? 'Generating…' : generatedSnapshot !== null ? 'Generate another version' : 'Generate comment';
}
function inputChanged() {
  $('stale').hidden = generatedSnapshot === null || generatedSnapshot === snapshot();
  const pairs = [
    ['consistent-attendance', 'attendance-routines', 'Consistent Attendance and Attendance Routines'],
  ];
  const conflicts = pairs.filter(([a,b]) => chosen.strengths.has(a) && chosen.nextSteps.has(b));
  $('contradictions').hidden = !conflicts.length;
  $('contradictions').textContent = conflicts.length
    ? `Check the context: ${conflicts.map(x => x[2]).join('; ')} may need clarification. You can adjust selections or explain the difference in observations, then generate when ready.` : '';
}
form.addEventListener('input', inputChanged);
form.addEventListener('change', inputChanged);
document.querySelectorAll('.chip').forEach(button => button.addEventListener('click', () => {
  const kind = button.dataset.bank || button.closest('[data-bank]').dataset.bank, id = button.dataset.id;
  if (chosen[kind].has(id)) chosen[kind].delete(id); else chosen[kind].add(id);
  button.setAttribute('aria-pressed', String(chosen[kind].has(id)));
  updateCounts();
  inputChanged();
}));
// Explicit tooltip state avoids sticky CSS hover/focus after a mouse click.
let activePreview = null, previewTimer = null, infoTrigger = null;
const descriptionDialog = $('descriptor-dialog');
function hidePreview() {
  clearTimeout(previewTimer);
  activePreview?.classList.remove('show-preview');
  activePreview = null;
}
function closeInfo() {
  hidePreview();
  if (descriptionDialog?.open) descriptionDialog.close();
}
function placeInfo(wrap) {
  const description = wrap.querySelector('.description');
  const rect = wrap.getBoundingClientRect();
  const left = Math.max(12, Math.min(rect.left, window.innerWidth - description.offsetWidth - 12));
  const above = rect.top - description.offsetHeight - 8;
  const top = above >= 12 ? above : Math.max(12, Math.min(rect.bottom + 8, window.innerHeight - description.offsetHeight - 12));
  description.style.left = `${left}px`;
  description.style.top = `${top}px`;
}
function showPreview(wrap) {
  if (descriptionDialog?.open) return;
  hidePreview(); activePreview = wrap;
  wrap.classList.add('show-preview'); placeInfo(wrap);
}
document.querySelectorAll('.chip-wrap').forEach(wrap => {
  wrap.addEventListener('pointerenter', event => {
    if (event.pointerType === 'mouse') showPreview(wrap);
  });
  wrap.addEventListener('pointerleave', () => {
    // Brief bridge gives the pointer time to cross the gap into the tooltip.
    clearTimeout(previewTimer);
    previewTimer = setTimeout(() => { if (activePreview === wrap) hidePreview(); }, 120);
  });
  const description = wrap.querySelector('.description');
  description.addEventListener('pointerenter', () => clearTimeout(previewTimer));
  description.addEventListener('pointerleave', () => { if (activePreview === wrap) hidePreview(); });
  wrap.addEventListener('focusin', event => {
    if (event.target.matches(':focus-visible')) showPreview(wrap);
  });
  wrap.addEventListener('focusout', event => { if (!wrap.contains(event.relatedTarget) && activePreview === wrap) hidePreview(); });
  wrap.querySelector('.chip').addEventListener('click', hidePreview);
});
document.querySelectorAll('.info').forEach(button => button.addEventListener('click', () => {
  closeInfo(); infoTrigger = button;
  const wrap = button.closest('.chip-wrap');
  $('descriptor-title').textContent = wrap.querySelector('.chip').textContent.replace(/\u2713/g, '').trim();
  $('descriptor-text').textContent = wrap.querySelector('.description').textContent;
  button.setAttribute('aria-expanded', 'true');
  descriptionDialog.showModal();
}));
$('descriptor-close')?.addEventListener('click', closeInfo);
if (descriptionDialog) {
  descriptionDialog.addEventListener('close', () => {
    infoTrigger?.setAttribute('aria-expanded', 'false');
    // Native dialog restores focus to the information button. Do not reopen its preview.
    hidePreview(); infoTrigger = null;
  });
  descriptionDialog.addEventListener('click', event => {
    const rect = descriptionDialog.getBoundingClientRect();
    if (event.target === descriptionDialog && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom)) closeInfo();
  });
}
document.addEventListener('pointerdown', event => {
  if (event.pointerType === 'touch') hidePreview();
  if (!event.target.closest('.chip-wrap') && !event.target.closest('#descriptor-dialog')) closeInfo();
});
document.addEventListener('keydown', event => { if (event.key === 'Escape') closeInfo(); });
window.addEventListener('scroll', hidePreview, {passive: true, capture: true});
window.addEventListener('resize', hidePreview);
window.addEventListener('blur', hidePreview);
editor.addEventListener('input', () => { updateEditor(); $('action-status').textContent = ''; });
form.addEventListener('submit', async event => {
  event.preventDefault();
  if (busy || !form.reportValidity()) return;
  const data = inputs();
  if (!data.name) { setStatus('Enter the student’s full first name.', true); $('name').focus(); return; }
  if (!data.strengths.length && !data.nextSteps.length && !data.supports.length) {
    setStatus('Choose at least one strength, next step or support.', true); document.querySelector('.chip').focus(); return;
  }
  if (editor.value.trim() && editor.value !== lastGenerated && !window.confirm('Generating another version will replace your edited comment. Continue?')) return;
  const sentSnapshot = JSON.stringify(data), version = ++requestVersion;
  controller?.abort(); controller = new AbortController();
  const activeController = controller;
  busy = true; generate.disabled = true; generate.setAttribute('aria-busy', 'true'); updateEditor();
  setStatus('Writing a thoughtful first draft…');
  // Editing is paused while a request runs, so a response cannot replace fresh manual edits.
  editor.readOnly = true;
  const timer = setTimeout(() => activeController.abort(), 55000);
  try {
    const response = await fetch('generate.php', {
      method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: activeController.signal,
      headers: {'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content},
      body: JSON.stringify(data)
    });
    const result = await response.json();
    if (version !== requestVersion) return;
    if (!response.ok || !result.ok || typeof result.comment !== 'string' || !result.comment.trim()) throw new Error(result.error || 'No usable comment was returned. Please try again.');
    editor.value = result.comment; lastGenerated = result.comment; generatedSnapshot = sentSnapshot;
    inputChanged(); setStatus('Your draft is ready. Review and personalise it before use.');
  } catch (error) {
    if (version !== requestVersion) return;
    setStatus(error.name === 'AbortError' ? 'The request timed out. Your existing comment is unchanged. Please try again.' : error instanceof SyntaxError || error instanceof TypeError ? 'The server could not be reached or returned an invalid response. Your existing comment is unchanged.' : error.message, true);
  } finally {
    clearTimeout(timer);
    if (version === requestVersion) {
      busy = false; controller = null; generate.disabled = false; generate.removeAttribute('aria-busy'); editor.readOnly = false; updateEditor();
    }
  }
});
$('copy').addEventListener('click', async () => {
  const text = editor.value;
  let copied = false;
  if (navigator.clipboard?.writeText) { try { await navigator.clipboard.writeText(text); copied = true; } catch {} }
  if (!copied) {
    const start = editor.selectionStart, end = editor.selectionEnd, active = document.activeElement;
    editor.focus(); editor.select();
    try { copied = document.execCommand('copy'); } catch {}
    editor.setSelectionRange(start, end); active?.focus();
  }
  $('action-status').textContent = copied ? 'Comment copied.' : 'Copy was unavailable. Select the comment and copy it using your keyboard or device menu.';
});
function downloadFilename(name, now = new Date()) {
  let safe = name.normalize('NFC').replace(/[<>:"/\\|?*\u0000-\u001F\u007F\u202A-\u202E\u2066-\u2069]/gu, '').replace(/[. ]+$/u, '').replace(/^\.+/u, '').trim();
  safe = [...safe].slice(0, 80).join('') || 'Student';
  if (/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i.test(safe)) safe = `Student_${safe}`;
  const pad = n => String(n).padStart(2, '0');
  return `${safe}_${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}_${pad(now.getHours())}-${pad(now.getMinutes())}-${pad(now.getSeconds())}.txt`;
}
$('save').addEventListener('click', () => {
  const blob = new Blob([editor.value], {type: 'text/plain;charset=utf-8'}), url = URL.createObjectURL(blob);
  const anchor = document.createElement('a'); anchor.href = url; anchor.download = downloadFilename($('name').value.trim());
  document.body.append(anchor); anchor.click(); anchor.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
  $('action-status').textContent = 'Text file download started.';
});
function hasStudentData() {
  return !!($('name').value.trim() || $('subject').value.trim() || $('observations').value.trim() || $('reframe').value.trim() || $('activities').value.trim() || editor.value.trim() || chosen.strengths.size || chosen.nextSteps.size || chosen.supports.size);
}
$('new-student').addEventListener('click', () => {
  if (hasStudentData() && !window.confirm('Clear all entered student details and the current comment?')) return;
  ++requestVersion; controller?.abort(); controller = null; busy = false;
  form.reset(); editor.value = ''; editor.readOnly = false; lastGenerated = ''; generatedSnapshot = null;
  Object.values(chosen).forEach(set => set.clear());
  document.querySelectorAll('.chip').forEach(button => button.setAttribute('aria-pressed', 'false'));
  updateCounts();
  generate.disabled = false; generate.removeAttribute('aria-busy'); setStatus(''); $('action-status').textContent = '';
  closeInfo(); inputChanged(); updateEditor(); $('name').focus();
});
window.addEventListener('beforeunload', event => { if (hasStudentData()) { event.preventDefault(); event.returnValue = ''; } });
// Do not allow a browser back/forward cache to restore an earlier student's details.
window.addEventListener('pageshow', event => { if (event.persisted) window.location.reload(); });
form.reset(); editor.value = ''; updateEditor();
