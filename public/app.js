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
        const save = document.createElement('button');
        save.type = 'button'; save.textContent = 'Save';
        save.addEventListener('click', async () => {
          const next = sessionComments.map(entry => entry.id === item.id ? {...entry, name: name.value, text: text.value} : entry);
          if (await replaceComments(next)) sessionMessage('Comment updated.');
        });
        const cancel = document.createElement('button');
        cancel.type = 'button'; cancel.textContent = 'Cancel';
        cancel.addEventListener('click', () => { editingId = null; renderSession(); });
        row.append(name, text, save, cancel);
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
