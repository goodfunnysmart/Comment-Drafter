'use strict';
const $ = id => document.getElementById(id);
const form = $('builder'), editor = $('comment'), generate = $('generate');
const chosen = {strengths: new Set(), nextSteps: new Set(), supports: new Set(), challenges: new Set()};
let controller = null, requestVersion = 0, generatedSnapshot = null, lastGenerated = '', busy = false, busyKind = '', tweakUndo = null, sessionBusy = false;
const fields = ['name', 'pronouns', 'observations', 'reframe', 'activities', 'adjustments', 'length'];
function inputs() {
  return {...Object.fromEntries(fields.map(id => [id, $(id).value.trim()])),
    strengths: [...chosen.strengths].sort(), nextSteps: [...chosen.nextSteps].sort(), supports: [...chosen.supports].sort(), challenges: [...chosen.challenges].sort()};
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
  const commitButton = $('commit-session');
  if (commitButton) commitButton.disabled = !text || sessionBusy;
  $('tweak-panel').hidden = !text;
  $('tweak-comment').disabled = busy || !text;
  $('tweak-comment').textContent = busy && busyKind === 'tweak' ? 'Tweaking…' : 'Tweak comment';
  $('undo-tweak').hidden = tweakUndo === null;
  generate.textContent = busy && busyKind === 'generate' ? 'Generating…' : generatedSnapshot !== null ? 'Generate another version' : 'Generate comment';
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
// The floating hint opens only from the information icon, on hover or keyboard focus.
// Tapping that icon opens the same text in the description dialog. Chips, labels and sections do not.
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
function placeInfo(anchor) {
  const wrap = anchor.closest('.chip-wrap');
  const description = wrap.querySelector('.description');
  const rect = anchor.getBoundingClientRect();
  const left = Math.max(12, Math.min(rect.left, window.innerWidth - description.offsetWidth - 12));
  const above = rect.top - description.offsetHeight - 8;
  const top = above >= 12 ? above : Math.max(12, Math.min(rect.bottom + 8, window.innerHeight - description.offsetHeight - 12));
  description.style.left = `${left}px`;
  description.style.top = `${top}px`;
  return wrap;
}
function showPreview(anchor) {
  if (descriptionDialog?.open) return;
  const wrap = anchor.closest('.chip-wrap');
  hidePreview(); activePreview = wrap;
  wrap.classList.add('show-preview'); placeInfo(anchor);
}
document.querySelectorAll('.info').forEach(button => {
  const wrap = button.closest('.chip-wrap');
  const description = wrap.querySelector('.description');
  button.addEventListener('pointerenter', event => {
    if (event.pointerType === 'mouse') showPreview(button);
  });
  button.addEventListener('pointerleave', () => {
    // Brief bridge gives the pointer time to cross the gap into the tooltip.
    clearTimeout(previewTimer);
    previewTimer = setTimeout(() => { if (activePreview === wrap) hidePreview(); }, 120);
  });
  description.addEventListener('pointerenter', () => clearTimeout(previewTimer));
  description.addEventListener('pointerleave', () => { if (activePreview === wrap) hidePreview(); });
  button.addEventListener('focus', () => {
    // Dialog close restores focus here. That return must not pop the hint open again.
    if (button.dataset.suppressHint === 'true') { delete button.dataset.suppressHint; return; }
    if (button.matches(':focus-visible')) showPreview(button);
  });
  button.addEventListener('blur', () => { if (activePreview === wrap) hidePreview(); });
  wrap.querySelector('.chip').addEventListener('click', hidePreview);
  button.addEventListener('click', () => {
    closeInfo(); infoTrigger = button;
    $('descriptor-title').textContent = wrap.querySelector('.chip').textContent.replace(/\u2713/g, '').trim();
    $('descriptor-text').textContent = wrap.querySelector('.description').textContent;
    button.setAttribute('aria-expanded', 'true');
    descriptionDialog.showModal();
  });
});
$('descriptor-close')?.addEventListener('click', closeInfo);
if (descriptionDialog) {
  descriptionDialog.addEventListener('close', () => {
    infoTrigger?.setAttribute('aria-expanded', 'false');
    // Native dialog restores focus to the information button. Do not reopen its preview.
    if (infoTrigger) {
      const trigger = infoTrigger;
      trigger.dataset.suppressHint = 'true';
      setTimeout(() => { delete trigger.dataset.suppressHint; }, 0);
    }
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
function readyForModel(data) {
  if (!data.name) { setStatus('Enter the student’s full first name.', true); $('name').focus(); return false; }
  if (!data.strengths.length && !data.nextSteps.length && !data.supports.length && !data.challenges.length && !data.adjustments) {
    setStatus('Choose at least one strength, next step, challenge or support, or describe another adjustment.', true); document.querySelector('.chip').focus(); return false;
  }
  return true;
}
async function runModelRequest(kind, data, statusText, onSuccess) {
  const sentSnapshot = JSON.stringify(inputs()), version = ++requestVersion;
  controller?.abort(); controller = new AbortController();
  const activeController = controller;
  busy = true; busyKind = kind; generate.disabled = true; $('tweak-comment').disabled = true;
  if (kind === 'generate') generate.setAttribute('aria-busy', 'true');
  if (kind === 'tweak') $('tweak-comment').setAttribute('aria-busy', 'true');
  updateEditor();
  setStatus(statusText);
  // Editing is paused while a request runs, so a response cannot replace fresh manual edits.
  editor.readOnly = true; $('tweak').readOnly = true;
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
    onSuccess(result, sentSnapshot);
  } catch (error) {
    if (version !== requestVersion) return;
    setStatus(error.name === 'AbortError' ? 'The request timed out. Your existing comment is unchanged. Please try again.' : error instanceof SyntaxError || error instanceof TypeError ? 'The server could not be reached or returned an invalid response. Your existing comment is unchanged.' : error.message, true);
  } finally {
    clearTimeout(timer);
    if (version === requestVersion) {
      busy = false; busyKind = ''; controller = null; generate.disabled = false; generate.removeAttribute('aria-busy');
      $('tweak-comment').removeAttribute('aria-busy'); editor.readOnly = false; $('tweak').readOnly = false; updateEditor();
    }
  }
}
form.addEventListener('submit', async event => {
  event.preventDefault();
  if (busy || !form.reportValidity()) return;
  const data = inputs();
  if (!readyForModel(data)) return;
  if (editor.value.trim() && editor.value !== lastGenerated && !window.confirm('Generating another version will replace your edited comment. Continue?')) return;
  data.mode = 'generate';
  await runModelRequest('generate', data, 'Writing a thoughtful first draft…', (result, sentSnapshot) => {
    editor.value = result.comment; lastGenerated = result.comment; generatedSnapshot = sentSnapshot; tweakUndo = null;
    inputChanged(); setStatus(typeof result.notice === 'string' && result.notice.trim() ? result.notice.trim() : 'Your draft is ready. Review and personalise it before use.');
  });
});
$('tweak-comment').addEventListener('click', async () => {
  if (busy) return;
  const instruction = $('tweak').value.trim();
  if (!editor.value.trim()) { setStatus('Generate or write a comment before tweaking it.', true); return; }
  if (!instruction) { setStatus('Describe what you would like changed.', true); $('tweak').focus(); return; }
  const data = inputs();
  if (!readyForModel(data)) return;
  const previous = editor.value, previousBaseline = lastGenerated;
  data.mode = 'tweak'; data.comment = editor.value; data.tweak = instruction;
  await runModelRequest('tweak', data, 'Applying that change…', result => {
    tweakUndo = {text: previous, baseline: previousBaseline};
    editor.value = result.comment; lastGenerated = result.comment; $('tweak').value = '';
    inputChanged(); setStatus(typeof result.notice === 'string' && result.notice.trim() ? result.notice.trim() : 'The tweak is ready. Review it before use. Undo restores the previous wording.');
  });
});
$('undo-tweak').addEventListener('click', () => {
  if (busy || !tweakUndo) return;
  editor.value = tweakUndo.text; lastGenerated = tweakUndo.baseline; tweakUndo = null;
  updateEditor(); setStatus('Restored the comment from before the tweak.');
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
  return !!($('name').value.trim() || $('observations').value.trim() || $('reframe').value.trim() || $('activities').value.trim() || $('adjustments').value.trim() || $('tweak').value.trim() || editor.value.trim() || chosen.strengths.size || chosen.nextSteps.size || chosen.supports.size || chosen.challenges.size || $('session-email')?.value.trim());
}
$('new-student').addEventListener('click', () => {
  if (hasStudentData() && !window.confirm('Clear all entered student details and the current comment?')) return;
  ++requestVersion; controller?.abort(); controller = null; busy = false; busyKind = ''; tweakUndo = null;
  form.reset(); editor.value = ''; editor.readOnly = false; lastGenerated = ''; generatedSnapshot = null;
  $('tweak').value = ''; $('tweak').readOnly = false;
  Object.values(chosen).forEach(set => set.clear());
  document.querySelectorAll('.chip').forEach(button => button.setAttribute('aria-pressed', 'false'));
  updateCounts();
  generate.disabled = false; generate.removeAttribute('aria-busy');
  $('tweak-comment').disabled = false; $('tweak-comment').removeAttribute('aria-busy');
  setStatus(''); $('action-status').textContent = '';
  closeInfo(); inputChanged(); updateEditor(); $('name').focus();
});
window.addEventListener('beforeunload', event => { if (hasStudentData()) { event.preventDefault(); event.returnValue = ''; } });
// Do not allow a browser back/forward cache to restore an earlier student's details.
window.addEventListener('pageshow', event => { if (event.persisted) window.location.reload(); });
form.reset(); editor.value = ''; updateEditor();

const DICTATE_LIMIT_MS = 90000;
const DICTATE_MAX_BYTES = 5000000;
const DICTATE_ICON = '<svg class="dictate-mic-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M12 14a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v5a3 3 0 0 0 3 3z" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M6.5 11a5.5 5.5 0 0 0 11 0M12 16.5v3M9 19.5h6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg><svg class="dictate-stop-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" hidden><rect x="7" y="7" width="10" height="10" rx="1.5" fill="currentColor"/></svg><span class="dictate-pulse" hidden></span><span class="dictate-spinner" hidden></span><span class="dictate-timer" aria-hidden="true"></span>';
const AUDIO_TYPES = new Set(['audio/webm', 'audio/mp4', 'audio/ogg', 'audio/wav', 'audio/wave', 'audio/x-wav', 'audio/mpeg', 'audio/mp3', 'audio/mpga', 'audio/m4a', 'audio/x-m4a', 'audio/aac', 'audio/x-aac', 'audio/flac']);
let activeDictation = null;
let dictationStarting = false;

function canDictate() {
  return window.isSecureContext === true && typeof MediaRecorder === 'function' && typeof navigator.mediaDevices?.getUserMedia === 'function';
}
function formatElapsed(ms) {
  const total = Math.max(0, Math.min(90, Math.floor(ms / 1000)));
  return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
}
function insertTranscript(textarea, transcript) {
  let insert = String(transcript ?? '').trim();
  if (!insert) return 'empty';
  const value = String(textarea.value ?? '');
  const start = Number.isInteger(textarea.selectionStart) ? textarea.selectionStart : value.length;
  const end = Number.isInteger(textarea.selectionEnd) ? Math.max(textarea.selectionEnd, start) : start;
  const before = value.slice(0, start);
  const after = value.slice(end);
  if (before && !/\s$/u.test(before)) insert = ` ${insert}`;
  if (after && !/^\s/u.test(after)) insert = `${insert} `;
  const max = textarea.maxLength;
  let clipped = false;
  if (typeof max === 'number' && max > 0 && before.length + insert.length + after.length > max) {
    const room = max - before.length - after.length;
    if (room <= 0) return 'full';
    insert = insert.slice(0, room);
    clipped = true;
    if (!insert.trim()) return 'full';
  }
  textarea.value = before + insert + after;
  const caret = before.length + insert.length;
  try { textarea.selectionStart = caret; textarea.selectionEnd = caret; } catch {}
  if (typeof textarea.dispatchEvent === 'function' && typeof Event === 'function') textarea.dispatchEvent(new Event('input', {bubbles: true}));
  return clipped ? 'clipped' : 'ok';
}
function dictateStatus(statusEl, message) {
  if (!statusEl) return;
  statusEl.textContent = message || '';
}
function setDictateMode(button, mode) {
  const wrap = button.closest('.dictate');
  button.classList.toggle('is-recording', mode === 'recording');
  button.classList.toggle('is-transcribing', mode === 'transcribing');
  wrap?.classList.toggle('is-recording', mode === 'recording');
  wrap?.classList.toggle('is-transcribing', mode === 'transcribing');
  button.disabled = mode === 'transcribing';
  if (mode === 'transcribing') button.setAttribute('aria-busy', 'true'); else button.removeAttribute('aria-busy');
  const mic = button.querySelector('.dictate-mic-icon');
  const stop = button.querySelector('.dictate-stop-icon');
  const pulse = button.querySelector('.dictate-pulse');
  const spinner = button.querySelector('.dictate-spinner');
  const timer = button.querySelector('.dictate-timer');
  if (mic) mic.hidden = mode !== 'idle';
  if (stop) stop.hidden = mode !== 'recording';
  if (pulse) pulse.hidden = mode !== 'recording';
  if (spinner) spinner.hidden = mode !== 'transcribing';
  if (timer && mode !== 'recording') timer.textContent = '';
  if (mode === 'idle') button.setAttribute('aria-label', button.dataset.label || 'Dictate');
  if (mode === 'transcribing') button.setAttribute('aria-label', 'Transcribing');
}
function preferredRecorderType() {
  if (typeof MediaRecorder.isTypeSupported !== 'function') return '';
  for (const type of ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4']) {
    if (MediaRecorder.isTypeSupported(type)) return type;
  }
  return '';
}
function attachDictation(button, field, statusEl) {
  if (!canDictate() || !button || button.dataset.dictateBound === '1') return;
  const target = field || document.getElementById(button.dataset.dictate || '');
  if (!target) return;
  button.dataset.dictateBound = '1';
  button.dataset.label = button.dataset.label || button.getAttribute('aria-label') || 'Dictate';
  button.hidden = false;
  const status = statusEl || document.getElementById(button.getAttribute('aria-describedby') || '');
  button.addEventListener('click', event => {
    event.preventDefault();
    onDictateClick(button, target, status);
  });
}
function sessionDictate(textarea, id) {
  if (!canDictate()) return null;
  const wrap = document.createElement('div');
  wrap.className = 'dictate';
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'dictate-mic';
  button.hidden = true;
  button.dataset.label = 'Dictate this session comment';
  button.setAttribute('aria-label', 'Dictate this session comment');
  button.innerHTML = DICTATE_ICON;
  const status = document.createElement('p');
  status.className = 'dictate-error help';
  status.setAttribute('role', 'status');
  status.id = `session-dictate-${id}`;
  button.setAttribute('aria-describedby', status.id);
  const described = textarea.getAttribute('aria-describedby');
  textarea.setAttribute('aria-describedby', described ? `${described} ${status.id}` : status.id);
  wrap.append(textarea, button);
  attachDictation(button, textarea, status);
  return {wrap, status};
}
function releaseStream(stream) {
  stream?.getTracks?.().forEach(track => track.stop());
}
function stopDictation(send) {
  const state = activeDictation;
  if (!state) return;
  state.send = send;
  clearInterval(state.timer);
  clearTimeout(state.limit);
  try {
    if (state.recorder.state !== 'inactive') state.recorder.stop();
    else finishDictation(state);
  } catch { finishDictation(state); }
}
function onDictateClick(button, field, statusEl) {
  if (button.classList.contains('is-transcribing') || dictationStarting) return;
  if (activeDictation?.button === button) { stopDictation(true); return; }
  if (activeDictation) { dictateStatus(statusEl, 'Stop the current recording first.'); return; }
  dictateStatus(statusEl, '');
  dictationStarting = true;
  navigator.mediaDevices.getUserMedia({audio: true}).then(stream => {
    dictationStarting = false;
    if (activeDictation) { releaseStream(stream); dictateStatus(statusEl, 'Stop the current recording first.'); return; }
    const mime = preferredRecorderType();
    let recorder;
    try { recorder = mime ? new MediaRecorder(stream, {mimeType: mime}) : new MediaRecorder(stream); }
    catch {
      releaseStream(stream);
      dictateStatus(statusEl, 'This browser could not start a recording. Try again.');
      return;
    }
    const state = {button, field, statusEl, stream, recorder, chunks: [], send: true, timer: 0, limit: 0, started: performance.now(), mime, settled: false};
    recorder.addEventListener('dataavailable', event => { if (event.data?.size) state.chunks.push(event.data); });
    recorder.addEventListener('stop', () => finishDictation(state));
    activeDictation = state;
    setDictateMode(button, 'recording');
    let announced = -1;
    const tick = () => {
      if (activeDictation !== state) return;
      const elapsedMs = performance.now() - state.started;
      const timer = button.querySelector('.dictate-timer');
      if (timer) timer.textContent = formatElapsed(elapsedMs);
      const second = Math.floor(elapsedMs / 1000);
      if (second !== announced) {
        announced = second;
        button.setAttribute('aria-label', `Stop dictation, ${formatElapsed(elapsedMs)} elapsed`);
      }
    };
    tick();
    state.timer = setInterval(tick, 250);
    state.limit = setTimeout(() => { if (activeDictation === state) stopDictation(true); }, DICTATE_LIMIT_MS);
    try { recorder.start(250); }
    catch {
      state.send = false;
      try { if (recorder.state !== 'inactive') recorder.stop(); else finishDictation(state); }
      catch { finishDictation(state); }
      dictateStatus(statusEl, 'This browser could not start a recording. Try again.');
    }
  }).catch(error => {
    dictationStarting = false;
    const name = error?.name || '';
    if (name === 'NotAllowedError' || name === 'PermissionDeniedError') dictateStatus(statusEl, 'Microphone permission was denied. Allow the microphone for this site, then try again.');
    else if (name === 'NotFoundError' || name === 'DevicesNotFoundError') dictateStatus(statusEl, 'No microphone was found. Connect a microphone and try again.');
    else if (name === 'NotReadableError' || name === 'TrackStartError') dictateStatus(statusEl, 'The microphone is in use by another app. Close it and try again.');
    else dictateStatus(statusEl, 'The microphone could not be started. Try again.');
  });
}
function finishDictation(state) {
  if (state.settled) return;
  state.settled = true;
  clearInterval(state.timer);
  clearTimeout(state.limit);
  releaseStream(state.stream);
  if (activeDictation === state) activeDictation = null;
  if (!state.send || !state.button.isConnected) { setDictateMode(state.button, 'idle'); return; }
  const blob = new Blob(state.chunks, {type: state.chunks[0]?.type || state.mime || 'audio/webm'});
  if (blob.size < 100) { setDictateMode(state.button, 'idle'); dictateStatus(state.statusEl, 'The recording was too short. Try again.'); return; }
  if (blob.size > DICTATE_MAX_BYTES) { setDictateMode(state.button, 'idle'); dictateStatus(state.statusEl, 'The recording is too large. Try a shorter clip.'); return; }
  const mime = String(blob.type || state.mime || 'audio/webm').split(';')[0].trim().toLowerCase();
  if (!AUDIO_TYPES.has(mime)) { setDictateMode(state.button, 'idle'); dictateStatus(state.statusEl, 'This recording format is not supported.'); return; }
  setDictateMode(state.button, 'transcribing');
  blobBase64(blob).then(audio => fetch('transcribe.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content},
    body: JSON.stringify({audio, mime})
  })).then(async response => {
    let data = {};
    try { data = await response.json(); } catch {}
    if (!response.ok || !data.ok) throw new Error(data.error || 'Dictation failed. Try again.');
    return data.text || '';
  }).then(text => {
    const placed = insertTranscript(state.field, text);
    setDictateMode(state.button, 'idle');
    if (placed === 'empty') dictateStatus(state.statusEl, 'No speech was heard. Try again.');
    else if (placed === 'full') dictateStatus(state.statusEl, 'This field is full, so the transcript was not added.');
    else if (placed === 'clipped') dictateStatus(state.statusEl, 'Some of the transcript was cut to fit this field.');
    else dictateStatus(state.statusEl, '');
    try { state.field.focus(); } catch {}
  }).catch(error => {
    setDictateMode(state.button, 'idle');
    const message = error?.message || '';
    dictateStatus(state.statusEl, /failed to fetch|network|load failed/i.test(message) || error instanceof TypeError ? 'Dictation could not reach the server. Try again.' : (message || 'Dictation failed. Try again.'));
  });
}
function blobBase64(blob) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(new Error('The recording could not be prepared. Try again.'));
    reader.onload = () => {
      const value = String(reader.result || '');
      const comma = value.indexOf(',');
      resolve(comma >= 0 ? value.slice(comma + 1) : value);
    };
    reader.readAsDataURL(blob);
  });
}
if (canDictate()) document.querySelectorAll('.dictate-mic').forEach(button => attachDictation(button));

if ($('session-toggle')) {
  const SESSION_KEY = 'commentDrafterSession';
  let sessionCode = '', sessionComments = [], sessionExpires = '', editingId = null;
  const sessionMessage = text => { $('session-message').textContent = text || ''; };
  const openPanel = () => { $('session-panel').hidden = false; $('session-toggle').setAttribute('aria-expanded', 'true'); };
  const closePanel = () => { $('session-panel').hidden = true; $('session-toggle').setAttribute('aria-expanded', 'false'); };
  function remember() {
    try {
      if (sessionCode) localStorage.setItem(SESSION_KEY, sessionCode);
      else localStorage.removeItem(SESSION_KEY);
    } catch {}
  }
  function applySession(data) {
    sessionCode = data.code || '';
    sessionComments = Array.isArray(data.comments) ? data.comments : [];
    sessionExpires = data.expiresLabel || '';
    editingId = null;
    remember();
    renderSession();
  }
  function forgetSession(message) {
    sessionCode = ''; sessionComments = []; sessionExpires = ''; editingId = null;
    remember();
    renderSession();
    sessionMessage(message);
  }
  async function sessionRequest(body, download = false) {
    const response = await fetch('session.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content},
      body: JSON.stringify(body)
    });
    if (download) {
      if (!response.ok) {
        let message = 'The download could not be started.';
        try { const data = await response.json(); if (data && data.error) message = data.error; } catch {}
        throw new Error(message);
      }
      return response.blob();
    }
    let data = {};
    try { data = await response.json(); } catch {}
    if (!response.ok || !data.ok) throw new Error(data.error || 'The session could not be updated.');
    return data;
  }
  async function persist() {
    sessionBusy = true; updateEditor();
    try {
      applySession(await sessionRequest({action: 'save', code: sessionCode, comments: sessionComments}));
      return true;
    } catch (error) {
      sessionMessage(error.message);
      return false;
    } finally {
      sessionBusy = false; updateEditor();
    }
  }
  async function replaceComments(next) {
    const copy = sessionComments.map(item => ({...item}));
    sessionComments = next;
    if (await persist()) return true;
    sessionComments = copy;
    renderSession();
    return false;
  }
  function renderSession() {
    if (activeDictation?.field?.closest?.('#session-list')) stopDictation(false);
    const count = sessionComments.length;
    $('session-count').textContent = String(count);
    $('session-list-count').textContent = `${count} ${count === 1 ? 'comment' : 'comments'}`;
    $('session-start').hidden = !!sessionCode;
    $('session-active').hidden = !sessionCode;
    $('session-code-display').value = sessionCode;
    if (sessionCode) $('session-expiry').textContent = sessionExpires ? `This session is removed on ${sessionExpires}.` : '';
    const list = $('session-list');
    list.replaceChildren();
    sessionComments.forEach((item, index) => {
      const row = document.createElement('li');
      row.className = 'session-item';
      if (editingId === item.id) {
        const name = document.createElement('input');
        name.value = item.name; name.maxLength = 100; name.setAttribute('aria-label', 'First name');
        const text = document.createElement('textarea');
        text.value = item.text; text.maxLength = 8000; text.setAttribute('aria-label', 'Comment');
        const dictated = sessionDictate(text, item.id);
        const save = document.createElement('button');
        save.type = 'button'; save.textContent = 'Save';
        save.addEventListener('click', async () => {
          const next = sessionComments.map(entry => entry.id === item.id ? {...entry, name: name.value, text: text.value} : entry);
          if (await replaceComments(next)) sessionMessage('Comment updated.');
        });
        const cancel = document.createElement('button');
        cancel.type = 'button'; cancel.textContent = 'Cancel';
        cancel.addEventListener('click', () => { editingId = null; renderSession(); });
        if (dictated) row.append(name, dictated.wrap, dictated.status, save, cancel);
        else row.append(name, text, save, cancel);
      } else {
        const name = document.createElement('p');
        name.className = 'session-name'; name.textContent = item.name;
        const text = document.createElement('p');
        text.className = 'session-text'; text.textContent = item.text;
        const actions = document.createElement('div');
        actions.className = 'session-item-actions';
        const edit = document.createElement('button');
        edit.type = 'button'; edit.textContent = 'Edit';
        edit.addEventListener('click', () => { editingId = item.id; renderSession(); });
        const remove = document.createElement('button');
        remove.type = 'button'; remove.textContent = 'Delete';
        remove.addEventListener('click', async () => {
          if (!window.confirm('Remove this comment from the session?')) return;
          await replaceComments(sessionComments.filter(entry => entry.id !== item.id));
        });
        const up = document.createElement('button');
        up.type = 'button'; up.textContent = 'Up'; up.disabled = index === 0;
        up.setAttribute('aria-label', `Move ${item.name} up`);
        up.addEventListener('click', () => moveComment(index, -1));
        const down = document.createElement('button');
        down.type = 'button'; down.textContent = 'Down'; down.disabled = index === count - 1;
        down.setAttribute('aria-label', `Move ${item.name} down`);
        down.addEventListener('click', () => moveComment(index, 1));
        actions.append(edit, remove, up, down);
        row.append(name, text, actions);
      }
      list.append(row);
    });
  }
  async function moveComment(index, delta) {
    const target = index + delta;
    if (sessionBusy || target < 0 || target >= sessionComments.length) return;
    const next = sessionComments.map(item => ({...item}));
    const [item] = next.splice(index, 1);
    next.splice(target, 0, item);
    await replaceComments(next);
  }
  function sessionItemId() {
    const bytes = new Uint8Array(8);
    if (window.crypto?.getRandomValues) window.crypto.getRandomValues(bytes);
    else for (let i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
    return [...bytes].map(byte => byte.toString(16).padStart(2, '0')).join('');
  }
  async function copyCode() {
    const text = $('session-code-display').value;
    let copied = false;
    if (navigator.clipboard?.writeText) { try { await navigator.clipboard.writeText(text); copied = true; } catch {} }
    if (!copied) { $('session-code-display').focus(); $('session-code-display').select(); try { copied = document.execCommand('copy'); } catch {} }
    sessionMessage(copied ? 'Code copied.' : 'Copy was unavailable. Select the code and copy it from the field.');
  }
  $('session-toggle').addEventListener('click', () => { if ($('session-panel').hidden) openPanel(); else closePanel(); });
  $('session-close').addEventListener('click', () => { closePanel(); $('session-toggle').focus(); });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !$('session-panel').hidden && !descriptionDialog?.open) { closePanel(); $('session-toggle').focus(); }
  });
  $('session-new').addEventListener('click', async () => {
    if (sessionBusy) return;
    sessionMessage('Starting a session…');
    try { applySession(await sessionRequest({action: 'create'})); sessionMessage('Write this code down. It is the only way to open these comments.'); $('session-copy-code').focus(); }
    catch (error) { sessionMessage(error.message); }
  });
  $('session-resume').addEventListener('click', async () => {
    const typed = $('session-code').value.trim();
    if (!typed) { sessionMessage('Enter a session code.'); $('session-code').focus(); return; }
    try { applySession(await sessionRequest({action: 'resume', code: typed})); $('session-code').value = ''; sessionMessage('Session opened.'); }
    catch (error) { sessionMessage(error.message); }
  });
  $('session-copy-code').addEventListener('click', copyCode);
  $('session-code-display').addEventListener('focus', () => $('session-code-display').select());
  $('session-send').addEventListener('click', async () => {
    const email = $('session-email').value.trim();
    if (!email) { sessionMessage('Enter an email address, or copy the code instead.'); $('session-email').focus(); return; }
    try {
      await sessionRequest({action: 'email', code: sessionCode, email});
      $('session-email').value = '';
      sessionMessage('The code was emailed. The address was not stored.');
    } catch (error) {
      if (!/valid email/i.test(error.message)) $('session-email').value = '';
      sessionMessage(error.message);
    }
  });
  async function downloadSession(format) {
    try {
      const blob = await sessionRequest({action: 'download', code: sessionCode, format}, true);
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url; anchor.download = format === 'docx' ? 'session-comments.docx' : 'session-comments.txt';
      document.body.append(anchor); anchor.click(); anchor.remove();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
      sessionMessage(format === 'docx' ? 'Word download started.' : 'Text download started.');
    } catch (error) { sessionMessage(error.message); }
  }
  $('session-txt').addEventListener('click', () => downloadSession('txt'));
  $('session-docx').addEventListener('click', () => downloadSession('docx'));
  $('session-leave').addEventListener('click', () => {
    forgetSession('This device has left the session. The comments stay stored until you clear them with the code, or the session expires.');
  });
  $('session-clear').addEventListener('click', async () => {
    if (!window.confirm('Delete every comment in this session? This cannot be undone.')) return;
    try { await sessionRequest({action: 'clear', code: sessionCode}); forgetSession('The session has been deleted.'); }
    catch (error) { sessionMessage(error.message); }
  });
  $('commit-session').addEventListener('click', async () => {
    const text = editor.value.trim();
    const name = $('name').value.trim();
    if (!text || sessionBusy) return;
    openPanel();
    if (!name) { sessionMessage('Enter the student’s first name before committing.'); $('name').focus(); return; }
    if (!sessionCode) { sessionMessage('Start or resume a session before committing this comment.'); $('session-new').focus(); return; }
    if (sessionComments.length >= 60) { sessionMessage('This session already has 60 comments.'); return; }
    if (await replaceComments([...sessionComments, {id: sessionItemId(), name, text}])) $('action-status').textContent = 'Saved to the session.';
  });
  renderSession();
  try {
    const saved = localStorage.getItem(SESSION_KEY) || '';
    if (saved) {
      sessionRequest({action: 'resume', code: saved}).then(applySession).catch(error => {
        if (/not found|expired|session code|security token/i.test(error.message)) {
          try { localStorage.removeItem(SESSION_KEY); } catch {}
        } else {
          $('session-code').value = saved;
        }
        openPanel();
        sessionMessage(error.message);
      });
    }
  } catch {}
}
