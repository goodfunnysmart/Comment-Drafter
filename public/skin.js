'use strict';
// Appearance only: no reload, storage or changes to student inputs / generation.
(() => {
  const root = document.documentElement;
  const controls = document.querySelectorAll('[data-set-skin]');
  controls.forEach(button => button.addEventListener('click', () => {
    const skin = button.dataset.setSkin;
    if (!['studio', 'classic', 'dark'].includes(skin) || root.dataset.skin === skin) return;
    root.dataset.skin = skin;
    controls.forEach(control => control.setAttribute('aria-pressed', String(control.dataset.setSkin === skin)));
    document.getElementById('skin-status').textContent = `${{studio: 'Studio', classic: 'Classic', dark: 'Dark'}[skin]} appearance selected. Your draft and selections are unchanged.`;
  }));
})();
