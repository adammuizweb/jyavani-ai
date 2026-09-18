(function () {
  'use strict';

  if (!window.JyavaniEditor) return;

  async function requestSuggestion(editor, context, selection) {
    var useSelection = selection && selection.to > selection.from;
    var response = await fetch(
      context.adminBasePath + '/?page=admin/tools/jyavani-ai/generate&action=generate',
      {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': context.csrfToken,
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
          resource_type: context.resourceType,
          editor_operation: context.operation,
          resource_id: context.resourceId,
          editor_mode: editor.getMode(),
          operation: 'improve',
          target: useSelection ? 'selection' : 'document',
          instruction: '',
          revision: editor.getRevision(),
          content: useSelection ? selection.text : editor.getContent()
        })
      }
    );

    var payload = await response.json().catch(function () { return null; });
    if (!response.ok || !payload || payload.ok !== true || typeof payload.html !== 'string') {
      throw new Error(payload && payload.error ? payload.error : 'AI request failed.');
    }
    return payload;
  }

  window.JyavaniEditor.registerButton({
    id: 'plugin.jyavani-ai.assist',
    label: 'AI Assistant',
    priority: 20,
    enabled: function (state) { return state.context.canUpdate; },
    onActivate: async function (state) {
      var editor = state.editor;
      var selection = state.selection;
      var revision = editor.getRevision();
      var result = await requestSuggestion(editor, state.context, selection);

      if (selection && selection.to > selection.from) {
        editor.insert(result.html, {
          format: 'html',
          range: selection,
          source: 'plugin.jyavani-ai'
        });
      } else {
        editor.setContent(result.html, {
          ifRevision: revision,
          selection: 'preserve',
          source: 'plugin.jyavani-ai'
        });
      }
      editor.focus();
    }
  });
})();
