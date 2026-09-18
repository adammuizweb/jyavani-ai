(function () {
  'use strict';

  document.querySelectorAll('[data-jai-provider-settings]').forEach(function (form) {
    var provider = form.querySelector('select[name="active_provider"]');
    var fields = form.querySelectorAll('[data-jai-provider-key]');
    var cryptoReady = form.getAttribute('data-jai-crypto-ready') === '1';
    if (!provider || !fields.length) return;

    function syncProviderKey() {
      fields.forEach(function (field) {
        var active = field.getAttribute('data-jai-provider-key') === provider.value;
        var input = field.querySelector('input');
        field.hidden = !active;
        if (input) input.disabled = !active || !cryptoReady;
      });
    }

    provider.addEventListener('change', syncProviderKey);
    syncProviderKey();
  });

  var config = window.JyavaniAIConfig;
  if (!config || !window.JyavaniEditor) return;

  function t(source) {
    return config.i18n && config.i18n[source] ? config.i18n[source] : source;
  }

  function format(source, value) {
    return t(source).replace('%s', String(value || ''));
  }

  function toast(type, message) {
    if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
      window.NewNotifToast.show({ type: type, title: t('AI Assistant'), message: message, duration: 4000 });
      return;
    }
    window.alert(message);
  }

  function createOption(value, label) {
    var option = document.createElement('option');
    option.value = value;
    option.textContent = label;
    return option;
  }

  function openAssistant(editor, editorContext, capturedSelection) {
    var previous = document.querySelector('.jai-dialog');
    if (previous) previous.remove();

    var overlay = document.createElement('div');
    overlay.className = 'jai-dialog';
    overlay.innerHTML =
      '<div class="jai-dialog__panel" role="dialog" aria-modal="true" aria-labelledby="jai-dialog-title">' +
        '<header class="jai-dialog__header"><div><p class="jai-dialog__eyebrow"></p><h2 id="jai-dialog-title"></h2></div><button type="button" class="jai-dialog__close" data-jai-close></button></header>' +
        '<div class="jai-dialog__body">' +
          '<div class="jai-dialog__controls">' +
            '<label><span data-jai-action-label></span><select data-jai-operation></select></label>' +
            '<label><span data-jai-target-label></span><select data-jai-target></select></label>' +
          '</div>' +
          '<label class="jai-dialog__instructions"><span data-jai-instruction-label></span><textarea rows="3" maxlength="2000" data-jai-instruction></textarea></label>' +
          '<p class="jai-dialog__privacy" data-jai-privacy></p>' +
          '<div class="jai-dialog__preview">' +
            '<label><span data-jai-source-label></span><textarea readonly data-jai-source></textarea></label>' +
            '<label><span data-jai-result-label></span><textarea readonly data-jai-result></textarea></label>' +
          '</div>' +
          '<p class="jai-dialog__status" role="status" aria-live="polite" data-jai-status></p>' +
        '</div>' +
        '<footer class="jai-dialog__footer"><a class="jai-dialog__settings" data-jai-settings hidden></a><span></span><button type="button" class="btn btn-outline" data-jai-cancel></button><button type="button" class="btn btn-primary" data-jai-generate></button><button type="button" class="btn btn-primary" data-jai-apply disabled></button></footer>' +
      '</div>';

    var operation = overlay.querySelector('[data-jai-operation]');
    var target = overlay.querySelector('[data-jai-target]');
    var instruction = overlay.querySelector('[data-jai-instruction]');
    var source = overlay.querySelector('[data-jai-source]');
    var result = overlay.querySelector('[data-jai-result]');
    var privacy = overlay.querySelector('[data-jai-privacy]');
    var status = overlay.querySelector('[data-jai-status]');
    var generate = overlay.querySelector('[data-jai-generate]');
    var apply = overlay.querySelector('[data-jai-apply]');
    var closeButton = overlay.querySelector('[data-jai-close]');
    var cancel = overlay.querySelector('[data-jai-cancel]');
    var settings = overlay.querySelector('[data-jai-settings]');
    var controller = null;
    var requestState = null;
    var returnFocus = document.querySelector('[data-editor-action="plugin.jyavani-ai.assist"]') || document.activeElement;
    var isolated = [];
    var previousOverflow = document.body.style.overflow;

    overlay.querySelector('.jai-dialog__eyebrow').textContent = t('AI writing assistant');
    overlay.querySelector('#jai-dialog-title').textContent = t('AI Assistant');
    closeButton.textContent = '\u00d7';
    closeButton.setAttribute('aria-label', t('Close'));
    overlay.querySelector('[data-jai-action-label]').textContent = t('Action');
    overlay.querySelector('[data-jai-target-label]').textContent = t('Apply to');
    overlay.querySelector('[data-jai-instruction-label]').textContent = t('Instructions');
    overlay.querySelector('[data-jai-source-label]').textContent = t('Source');
    overlay.querySelector('[data-jai-result-label]').textContent = t('Generated result');
    instruction.placeholder = t('Describe the intended result, tone, language, or constraints.');
    cancel.textContent = t('Cancel');
    generate.textContent = t('Generate');
    apply.textContent = t('Apply result');

    [
      ['improve', 'Improve writing'],
      ['rewrite', 'Rewrite'],
      ['shorten', 'Shorten'],
      ['expand', 'Expand'],
      ['translate', 'Translate'],
      ['custom', 'Custom instructions']
    ].forEach(function (item) { operation.appendChild(createOption(item[0], t(item[1]))); });

    var selectedText = capturedSelection && String(capturedSelection.text || '').trim() !== '';
    var selectionOption = createOption('selection', t('Selected text'));
    selectionOption.disabled = !selectedText;
    target.appendChild(selectionOption);
    target.appendChild(createOption('document', t('Entire document')));
    target.value = selectedText ? 'selection' : 'document';

    if (config.settingsUrl) {
      settings.hidden = false;
      settings.href = config.settingsUrl;
      settings.textContent = t('Open settings');
    }

    function sourceValue() {
      return target.value === 'selection' && capturedSelection
        ? String(capturedSelection.text || '')
        : editor.getContent();
    }

    function refreshSource() {
      source.value = sourceValue();
      privacy.textContent = target.value === 'selection'
        ? format('Only the selected text will be sent to %s.', config.providerHost)
        : format('The complete document will be sent to %s.', config.providerHost);
    }

    function setBusy(busy) {
      generate.disabled = busy || !config.providerReady;
      apply.disabled = busy || !requestState;
      operation.disabled = busy;
      target.disabled = busy;
      instruction.disabled = busy;
      generate.textContent = busy ? t('Generating...') : t('Generate');
    }

    function invalidateResult() {
      requestState = null;
      result.value = '';
      apply.disabled = true;
    }

    function close() {
      if (controller) controller.abort();
      isolated.forEach(function (item) {
        item.element.inert = item.inert;
        if (item.ariaHidden === null) item.element.removeAttribute('aria-hidden');
        else item.element.setAttribute('aria-hidden', item.ariaHidden);
      });
      document.body.style.overflow = previousOverflow;
      overlay.remove();
      if (returnFocus && returnFocus.isConnected && typeof returnFocus.focus === 'function') returnFocus.focus();
      else editor.focus();
    }

    function focusable() {
      return Array.from(overlay.querySelectorAll('button:not([disabled]), a[href], select:not([disabled]), textarea:not([disabled])'));
    }

    function onKeydown(event) {
      if (event.key === 'Escape') {
        event.preventDefault();
        close();
        return;
      }
      if (event.key !== 'Tab') return;
      var items = focusable();
      if (!items.length) return;
      var first = items[0];
      var last = items[items.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }

    async function generateContent() {
      var custom = instruction.value.trim();
      if (target.value === 'selection' && !selectedText) {
        status.textContent = t('Select text in the editor first.');
        return;
      }
      if ((operation.value === 'translate' || operation.value === 'custom') && custom === '') {
        status.textContent = t('Enter instructions for this action.');
        instruction.focus();
        return;
      }
      if (!config.providerReady) {
        status.textContent = t('The AI provider is not configured.');
        return;
      }

      requestState = null;
      result.value = '';
      status.textContent = '';
      controller = new AbortController();
      var timeout = setTimeout(function () { controller.abort(); }, Number(config.requestTimeoutMs) || 70000);
      var requestRevision = editor.getRevision();
      var requestSelection = target.value === 'selection' ? capturedSelection : null;
      setBusy(true);
      try {
        var response = await fetch(config.endpoint, {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          signal: controller.signal,
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-Token': editorContext.csrfToken,
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: JSON.stringify({
            resource_type: editorContext.resourceType,
            editor_operation: editorContext.operation,
            resource_id: editorContext.resourceId,
            editor_mode: editor.getMode(),
            operation: operation.value,
            target: target.value,
            instruction: custom,
            content: sourceValue(),
            revision: requestRevision
          })
        });
        var payload = await response.json().catch(function () { return null; });
        if (!response.ok || !payload || payload.ok !== true || typeof payload.html !== 'string') {
          throw new Error(payload && payload.error ? payload.error : t('The request failed.'));
        }
        if (payload.html.trim() === '') throw new Error(t('No generated content was returned.'));
        requestState = {
          html: payload.html,
          revision: requestRevision,
          selection: requestSelection,
          target: target.value
        };
        result.value = payload.html;
        status.textContent = '';
      } catch (error) {
        if (error && error.name === 'AbortError') status.textContent = t('The request failed.');
        else status.textContent = error && error.message ? error.message : t('The request failed.');
      } finally {
        clearTimeout(timeout);
        controller = null;
        setBusy(false);
      }
    }

    function applyResult() {
      if (!requestState) return;
      try {
        if (requestState.target === 'selection') {
          editor.insert(requestState.html, {
            format: 'html',
            range: requestState.selection,
            source: 'plugin.jyavani-ai'
          });
        } else {
          editor.setContent(requestState.html, {
            ifRevision: requestState.revision,
            selection: 'end',
            source: 'plugin.jyavani-ai'
          });
        }
        close();
        toast('success', t('Generated content was applied. Review it before saving.'));
      } catch (error) {
        if (error && (error.code === 'EDITOR_REVISION_CONFLICT' || error.code === 'EDITOR_SELECTION_MODE_MISMATCH')) {
          status.textContent = t('The editor changed while AI was generating. Generate again to avoid overwriting newer work.');
          requestState = null;
          apply.disabled = true;
          return;
        }
        status.textContent = error && error.message ? error.message : t('The request failed.');
      }
    }

    operation.addEventListener('change', function () {
      invalidateResult();
      if (operation.value === 'translate' || operation.value === 'custom') instruction.focus();
    });
    target.addEventListener('change', function () {
      invalidateResult();
      refreshSource();
    });
    instruction.addEventListener('input', invalidateResult);
    generate.addEventListener('click', generateContent);
    apply.addEventListener('click', applyResult);
    closeButton.addEventListener('click', close);
    cancel.addEventListener('click', close);
    overlay.addEventListener('mousedown', function (event) { if (event.target === overlay) close(); });
    overlay.addEventListener('keydown', onKeydown);
    document.body.appendChild(overlay);
    Array.from(document.body.children).forEach(function (element) {
      if (element === overlay || element.tagName === 'SCRIPT') return;
      isolated.push({ element: element, inert: element.inert, ariaHidden: element.getAttribute('aria-hidden') });
      element.inert = true;
      element.setAttribute('aria-hidden', 'true');
    });
    document.body.style.overflow = 'hidden';
    refreshSource();
    if (!config.providerReady) status.textContent = t('The AI provider is not configured.');
    setBusy(false);
    operation.focus();
  }

  window.JyavaniEditor.ready().then(function (editor) {
    if (!editor) return;
    var context = editor.context();
    if (!context.canUpdate) return;
    window.JyavaniEditor.registerButton({
      id: 'plugin.jyavani-ai.assist',
      label: t('AI Assistant'),
      title: t('Generate or rewrite content'),
      priority: 20,
      enabled: function (state) { return state.context.canUpdate; },
      onActivate: function (state) { openAssistant(state.editor, state.context, state.selection); }
    });
  });
})();
