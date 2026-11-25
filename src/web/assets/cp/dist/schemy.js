(function (window, document) {
  'use strict';

  let uid = 0;
  const typeLists = {};

  function types() {
    return window.SchemyTypes || {};
  }

  function makeDatalist(values) {
    const list = document.createElement('datalist');
    list.id = 'schemy-list-' + ++uid;
    fillDatalist(list, values);
    document.body.appendChild(list);
    return list;
  }

  function fillDatalist(list, values) {
    list.textContent = '';
    values.forEach(function (value) {
      const option = document.createElement('option');
      option.value = value;
      list.appendChild(option);
    });
  }

  function typeListFor(suggestions) {
    const key = suggestions.join(',');
    if (!typeLists[key]) {
      typeLists[key] = makeDatalist(suggestions);
    }
    return typeLists[key];
  }

  class Editor {
    constructor(container) {
      this.container = container;
      this.allowField = container.dataset.allowField === '1';
      this.typeInput = container.querySelector('[data-schemy-type]');
      this.rows = container.querySelector('[data-schemy-rows]');
      this.template = container.querySelector('template[data-schemy-row-template]');
      this.nextIndex = this.rows.querySelectorAll('[data-schemy-row]').length;

      let suggestions = [];
      try {
        suggestions = JSON.parse(container.dataset.typeSuggestions || '[]');
      } catch (e) {}
      if (!suggestions.length) {
        suggestions = Object.keys(types());
      }
      this.typeInput.setAttribute('list', typeListFor(suggestions).id);

      this.propertyList = makeDatalist([]);
      this.refreshProperties();

      this.rows.querySelectorAll('[data-schemy-row]').forEach((row) => this.initRow(row));

      this.typeInput.addEventListener('input', () => this.refreshProperties());
      this.typeInput.addEventListener('change', () => {
        this.refreshProperties();
        if (!this.rows.querySelector('[data-schemy-row]')) {
          this.addDefaults();
        }
      });

      container.querySelector('[data-schemy-add]').addEventListener('click', () => {
        const row = this.addRow();
        row.querySelector('[data-schemy-property]').focus();
      });

      container.querySelector('[data-schemy-defaults]').addEventListener('click', () => this.addDefaults());
    }

    currentType() {
      return types()[this.typeInput.value.trim()] || null;
    }

    refreshProperties() {
      const type = this.currentType();
      fillDatalist(this.propertyList, type ? type.properties : []);
    }

    initRow(row) {
      const property = row.querySelector('[data-schemy-property]');
      const source = row.querySelector('[data-schemy-source]');

      property.setAttribute('list', this.propertyList.id);

      row.querySelector('[data-schemy-remove]').addEventListener('click', () => row.remove());

      if (source) {
        source.addEventListener('change', () => this.syncSource(row));
      }
    }

    syncSource(row) {
      const source = row.querySelector('[data-schemy-source]');
      const isField = source && source.value === 'field';
      const field = row.querySelector('[data-schemy-field]');

      if (field) {
        field.hidden = !isField;
      }
      row.querySelector('[data-schemy-template]').hidden = isField;
    }

    addRow(values) {
      const html = this.template.innerHTML.replace(/__ROW__/g, String(this.nextIndex++));
      const tbody = document.createElement('tbody');
      tbody.innerHTML = html.trim();
      const row = tbody.querySelector('[data-schemy-row]');

      if (values) {
        row.querySelector('[data-schemy-property]').value = values.property;
        const source = row.querySelector('[data-schemy-source]');

        if (source) {
          source.value = values.source;
        }

        if (values.source === 'field') {
          const select = row.querySelector('[data-schemy-field] select');
          if (select && select.querySelector('option[value="' + CSS.escape(values.value) + '"]')) {
            select.value = values.value;
          }
        } else {
          row.querySelector('[data-schemy-template]').value = values.value;
        }
      }

      this.rows.appendChild(row);
      this.initRow(row);
      this.syncSource(row);
      return row;
    }

    addDefaults() {
      const type = this.currentType();
      if (!type) {
        return;
      }

      const existing = new Set(
        Array.from(this.rows.querySelectorAll('[data-schemy-property]')).map((input) => input.value.trim())
      );

      type.defaults.forEach((row) => {
        if (existing.has(row.property) || (row.source === 'field' && !this.allowField)) {
          return;
        }
        this.addRow(row);
      });
    }
  }

  function initEditors(root) {
    root.querySelectorAll('[data-schemy-editor]').forEach(function (container) {
      if (container.dataset.schemyReady) {
        return;
      }
      container.dataset.schemyReady = '1';
      new Editor(container);
    });
  }

  function initField(id) {
    const root = document.getElementById(id);
    if (!root || root.dataset.schemyReady) {
      return;
    }
    root.dataset.schemyReady = '1';

    initEditors(root);

    const mode = root.querySelector('[data-schemy-mode]');
    const syncMode = function () {
      root.querySelectorAll('[data-schemy-summary]').forEach(function (el) {
        el.hidden = el.dataset.schemySummary !== mode.value;
      });
    };
    mode.addEventListener('change', syncMode);
    syncMode();

    const previewButton = root.querySelector('[data-schemy-preview]');
    if (previewButton) {
      const output = root.querySelector('[data-schemy-preview-output]');

      previewButton.addEventListener('click', function () {
        output.hidden = false;
        output.textContent = Craft.t('schemy', 'Loading…');

        Craft.sendActionRequest('POST', 'schemy/preview', {
          data: {
            elementId: previewButton.dataset.elementId,
            siteId: previewButton.dataset.siteId,
          },
        })
          .then(function (response) {
            output.textContent = response.data.json || '—';
            renderPreviewValidation(root, response.data.validation);
          })
          .catch(function () {
            output.textContent = Craft.t('schemy', 'Preview unavailable.');
            renderPreviewValidation(root, null);
          });
      });
    }
  }

  const SEVERITY_LABELS = {
    error: function () { return Craft.t('schemy', 'Error'); },
    warning: function () { return Craft.t('schemy', 'Warning'); },
    notice: function () { return Craft.t('schemy', 'Notice'); },
  };

  function summarize(validation) {
    if (!validation.errors && !validation.warnings && !validation.notices) {
      return Craft.t('schemy', 'No issues found.');
    }
    return Craft.t('schemy', '{errors, plural, =1{# error} other{# errors}}, {warnings, plural, =1{# warning} other{# warnings}}, {notices, plural, =1{# notice} other{# notices}}', {
      errors: validation.errors,
      warnings: validation.warnings,
      notices: validation.notices,
    });
  }

  // Inline code in messages is written `like this`; escape everything else
  function formatMessage(message) {
    const span = document.createElement('span');
    message.split('`').forEach(function (part, i) {
      if (i % 2) {
        const code = document.createElement('code');
        code.textContent = part;
        span.appendChild(code);
      } else {
        span.appendChild(document.createTextNode(part));
      }
    });
    return span;
  }

  function fillIssues(tbody, issues) {
    tbody.textContent = '';
    issues.forEach(function (issue) {
      const tr = document.createElement('tr');
      const severity = document.createElement('td');
      const badge = document.createElement('span');
      badge.className = 'schemy-severity schemy-severity-' + issue.severity;
      badge.textContent = (SEVERITY_LABELS[issue.severity] || function () { return issue.severity; })();
      severity.appendChild(badge);

      const where = document.createElement('td');
      const path = document.createElement('code');
      path.textContent = issue.path;
      where.appendChild(path);

      const message = document.createElement('td');
      message.appendChild(formatMessage(issue.message));

      tr.append(severity, where, message);
      tbody.appendChild(tr);
    });
  }

  function renderPreviewValidation(root, validation) {
    const container = root.querySelector('[data-schemy-preview-validation]');
    if (!container) {
      return;
    }
    if (!validation) {
      container.hidden = true;
      return;
    }
    container.hidden = false;
    container.querySelector('[data-schemy-validation-summary]').textContent = summarize(validation);
    const table = container.querySelector('table');
    table.hidden = !validation.issues.length;
    fillIssues(table.querySelector('tbody'), validation.issues);
  }

  function initValidator(id) {
    const root = document.getElementById(id);
    if (!root || root.dataset.schemyReady) {
      return;
    }
    root.dataset.schemyReady = '1';

    const button = root.querySelector('[data-schemy-validate]');
    const spinner = root.querySelector('[data-schemy-spinner]');
    const results = root.querySelector('[data-schemy-results]');
    const heading = root.querySelector('[data-schemy-summary-heading]');
    const table = root.querySelector('[data-schemy-issues]');
    const jsonWrapper = root.querySelector('[data-schemy-json-wrapper]');
    const json = root.querySelector('[data-schemy-json]');
    const textarea = root.querySelector('textarea[name="json"]');

    button.addEventListener('click', function () {
      const element = root.querySelector('.elementselect .element[data-id]');
      const data = element
        ? {entryId: element.dataset.id, siteId: element.dataset.siteId || ''}
        : {json: textarea.value};

      if (!element && !textarea.value.trim()) {
        Craft.cp.displayError(Craft.t('schemy', 'Select an entry or paste some JSON-LD.'));
        return;
      }

      button.disabled = true;
      spinner.hidden = false;

      Craft.sendActionRequest('POST', 'schemy/validator/validate', {data: data})
        .then(function (response) {
          const validation = response.data;
          results.hidden = false;
          heading.textContent = summarize(validation);
          table.hidden = !validation.issues.length;
          fillIssues(table.querySelector('tbody'), validation.issues);
          jsonWrapper.hidden = !validation.json;
          json.textContent = validation.json;
        })
        .catch(function (e) {
          Craft.cp.displayError((e.response && e.response.data && e.response.data.message) || Craft.t('schemy', 'Validation failed.'));
        })
        .finally(function () {
          button.disabled = false;
          spinner.hidden = true;
        });
    });
  }

  window.Schemy = {
    initAll: function (root) {
      initEditors(root || document);
    },
    initField: initField,
    initValidator: initValidator,
  };
})(window, document);
