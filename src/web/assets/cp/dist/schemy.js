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
          })
          .catch(function () {
            output.textContent = Craft.t('schemy', 'Preview unavailable.');
          });
      });
    }
  }

  window.Schemy = {
    initAll: function (root) {
      initEditors(root || document);
    },
    initField: initField,
  };
})(window, document);
