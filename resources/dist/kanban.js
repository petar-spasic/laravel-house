// laravel-kanban local UI: drag between columns, inline changes, card dialog, polling. No dependencies.
(() => {
  'use strict';

  const doc = document;
  doc.documentElement.classList.add('js');

  const token = doc.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const pollMs = Number(doc.body.dataset.pollMs) || 3000;
  const dialog = doc.querySelector('[data-dialog]');
  const dialogBody = dialog?.querySelector('[data-dialog-body]');
  let columns = doc.querySelector('[data-columns]');
  let etag = columns?.dataset.etag || null;
  let dragged = null;
  let busy = false;

  if (!columns) {
    return; // a card page: its forms post without script
  }

  const parse = (html) => {
    const template = doc.createElement('template');
    template.innerHTML = html;
    return template.content;
  };

  const notice = (html) => {
    const target = dialog?.open ? dialog.querySelector('[data-notice]') : doc.querySelector('main [data-notice]');
    if (target) {
      target.replaceChildren(parse(html));
    }
  };

  const clearNotice = () => {
    doc.querySelectorAll('[data-notice]').forEach((el) => el.replaceChildren());
  };

  const swap = (html, tag) => {
    const next = parse(html).querySelector('[data-columns]');
    if (!next) {
      return;
    }
    const open = new Set([...columns.querySelectorAll('details[open][data-stage]')].map((d) => d.dataset.stage));
    next.querySelectorAll('details[data-stage]').forEach((d) => { d.open = open.has(d.dataset.stage); });
    columns.replaceWith(next);
    columns = next;
    etag = tag || next.dataset.etag || etag;
  };

  const loadDetail = async (url) => {
    const response = await fetch(url, { headers: { 'X-Kanban': 'fragment' }, credentials: 'same-origin' });
    if (response.ok && dialogBody) {
      dialogBody.replaceChildren(parse(await response.text()));
      dialog.dataset.url = url;
    }
    return response.ok;
  };

  const post = async (url, body) => {
    busy = true;
    try {
      const response = await fetch(url, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: { 'X-CSRF-TOKEN': token, 'X-Kanban': 'fragment', Accept: 'text/html' },
      });
      const html = await response.text();
      if (response.ok) {
        clearNotice();
        swap(html, response.headers.get('ETag'));
        if (dialog?.open && dialog.dataset.url) {
          await loadDetail(dialog.dataset.url);
        }
      } else if (response.status === 409) {
        if (dialog?.open && dialogBody) {
          dialogBody.replaceChildren(parse(html));
        } else {
          notice('<div class="notice notice-e" role="alert"><p>The card changed elsewhere; the board is reloaded.</p></div>');
        }
        await poll(true);
      } else if (response.status === 422 || response.status === 503) {
        notice(html);
        await poll(true);
      } else {
        notice('<div class="notice notice-e" role="alert"><p>Not saved (HTTP ' + response.status + ').</p></div>');
      }
    } catch (error) {
      notice('<div class="notice notice-e" role="alert"><p>Not saved: the server is unreachable.</p></div>');
    } finally {
      busy = false;
    }
  };

  const poll = async (force = false) => {
    if (!force && (busy || doc.hidden || dragged || dialog?.open)) {
      return;
    }
    const headers = { 'X-Kanban': 'fragment' };
    if (etag && !force) {
      headers['If-None-Match'] = etag;
    }
    try {
      const response = await fetch(columns.dataset.columnsUrl, { headers, credentials: 'same-origin', cache: 'no-store' });
      if (response.status === 200) {
        swap(await response.text(), response.headers.get('ETag'));
      }
    } catch (error) {
      // offline for a moment: the next tick retries
    }
  };

  // Drag a tile onto another column.
  doc.addEventListener('dragstart', (event) => {
    const tile = event.target.closest?.('[data-card]');
    if (!tile) {
      return;
    }
    dragged = tile;
    tile.classList.add('is-dragging');
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', tile.dataset.card);
  });

  doc.addEventListener('dragend', () => {
    dragged?.classList.remove('is-dragging');
    dragged = null;
    doc.querySelectorAll('.is-over').forEach((el) => el.classList.remove('is-over'));
  });

  const dropTarget = (event) => {
    const column = event.target.closest?.('[data-stage]');
    return dragged && column && column.dataset.drop === '1' && column.dataset.stage !== dragged.closest('[data-stage]')?.dataset.stage
      ? column
      : null;
  };

  doc.addEventListener('dragover', (event) => {
    const column = dropTarget(event);
    if (column) {
      event.preventDefault();
      event.dataTransfer.dropEffect = 'move';
      column.classList.add('is-over');
    }
  });

  doc.addEventListener('dragleave', (event) => {
    const column = event.target.closest?.('[data-stage]');
    if (column && !column.contains(event.relatedTarget)) {
      column.classList.remove('is-over');
    }
  });

  doc.addEventListener('drop', (event) => {
    const column = dropTarget(event);
    if (!column) {
      return;
    }
    event.preventDefault();
    const tile = dragged;
    const body = new FormData();
    body.set('to', column.dataset.stage);
    body.set('rev', tile.dataset.rev);
    column.querySelector('.tiles')?.append(tile);
    post(tile.dataset.stageUrl, body);
  });

  // Forms: posted in place, selects marked data-autosubmit post on change.
  doc.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-fetch]');
    if (!form) {
      return;
    }
    event.preventDefault();
    post(form.action, new FormData(form));
  });

  doc.addEventListener('change', (event) => {
    if (event.target.matches?.('select[data-autosubmit]')) {
      event.target.form.requestSubmit();
    }
  });

  // Card details in a dialog; the link still opens the full page with a modifier key or without script.
  doc.addEventListener('click', async (event) => {
    const link = event.target.closest?.('a[data-detail]');
    if (!link || !dialog || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
      return;
    }
    event.preventDefault();
    if (await loadDetail(link.dataset.detail)) {
      dialog.showModal();
    } else {
      location.href = link.href;
    }
  });

  dialog?.addEventListener('close', () => {
    dialogBody?.replaceChildren();
    delete dialog.dataset.url;
    poll(true);
  });

  dialog?.addEventListener('click', (event) => {
    if (event.target === dialog) {
      dialog.close();
    }
  });

  doc.addEventListener('visibilitychange', () => {
    if (!doc.hidden) {
      poll();
    }
  });

  setInterval(poll, pollMs);
})();
