/**
 * Search criteria form: renders every available CRM field at once.
 * Empty values are ignored, so the user can fill one or many fields.
 */
(function (global) {
    const OPERATORS = [
        { v: 'equal', t: 'equals' },
        { v: 'not_equal', t: 'not equals' },
        { v: 'contains', t: 'contains' },
        { v: 'not_contains', t: 'does not contain' },
        { v: 'greater', t: 'greater than' },
        { v: 'less', t: 'less than' },
        { v: 'empty', t: 'is empty' },
        { v: 'not_empty', t: 'is not empty' },
    ];

    const FALLBACK = {
        deal: [
            { code: 'TITLE', title: 'Title' },
            { code: 'STAGE_ID', title: 'Stage' },
            { code: 'OPPORTUNITY', title: 'Amount' },
            { code: 'COMPANY_ID', title: 'Company' },
            { code: 'CONTACT_ID', title: 'Contact' },
            { code: 'ASSIGNED_BY_ID', title: 'Responsible' },
            { code: 'SOURCE_ID', title: 'Source' },
            { code: 'COMMENTS', title: 'Comment' },
        ],
        lead: [
            { code: 'TITLE', title: 'Title' },
            { code: 'NAME', title: 'Name' },
            { code: 'LAST_NAME', title: 'Last name' },
            { code: 'STATUS_ID', title: 'Status' },
            { code: 'PHONE', title: 'Phone' },
            { code: 'EMAIL', title: 'Email' },
            { code: 'COMPANY_ID', title: 'Company' },
            { code: 'ASSIGNED_BY_ID', title: 'Responsible' },
            { code: 'SOURCE_ID', title: 'Source' },
            { code: 'COMMENTS', title: 'Comment' },
        ],
        contact: [
            { code: 'NAME', title: 'Name' },
            { code: 'LAST_NAME', title: 'Last name' },
            { code: 'PHONE', title: 'Phone' },
            { code: 'EMAIL', title: 'Email' },
            { code: 'COMPANY_ID', title: 'Company' },
            { code: 'ASSIGNED_BY_ID', title: 'Responsible' },
            { code: 'COMMENTS', title: 'Comment' },
        ],
        company: [
            { code: 'TITLE', title: 'Title' },
            { code: 'PHONE', title: 'Phone' },
            { code: 'EMAIL', title: 'Email' },
            { code: 'ASSIGNED_BY_ID', title: 'Responsible' },
            { code: 'SOURCE_ID', title: 'Source' },
            { code: 'COMMENTS', title: 'Comment' },
        ],
        smart: [{ code: 'title', title: 'Title' }],
    };

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
    }

    function ConditionsForm(root, options) {
        this.root = root;
        this.options = options || {};
        this.fieldsApi = this.options.fieldsApi || '';
        this.auth = this.options.auth || {};
        this.fields = FALLBACK.deal;
        this.entityEl = root.querySelector('[data-cf-entity]');
        this.smartRow = root.querySelector('[data-cf-smart-row]');
        this.smartTypeIdEl = root.querySelector('[data-cf-smart-type]');
        this.listEl = root.querySelector('[data-cf-list]');
        this.countEl = root.querySelector('[data-cf-count]');
        this.addBtn = root.querySelector('[data-cf-add]');
        this.fieldsCache = {};

        if (this.addBtn) {
            this.addBtn.style.display = 'none';
        }
        if (this.entityEl) {
            this.entityEl.addEventListener('change', () => this.onEntityChange());
        }
        if (this.smartTypeIdEl) {
            this.smartTypeIdEl.addEventListener('change', () => this.onEntityChange());
        }
    }

    ConditionsForm.prototype.cacheKey = function () {
        const e = this.entityEl ? this.entityEl.value : 'deal';
        const smart = e === 'smart' && this.smartTypeIdEl ? this.smartTypeIdEl.value : '0';
        return e + ':' + smart;
    };

    ConditionsForm.prototype.loadFields = async function () {
        const key = this.cacheKey();
        if (this.fieldsCache[key]) {
            this.fields = this.fieldsCache[key];
            return this.fields;
        }

        const entity = this.entityEl ? this.entityEl.value : 'deal';
        this.fields = FALLBACK[entity] || FALLBACK.deal;

        if (!this.options.hasAuth || !this.fieldsApi) {
            return this.fields;
        }

        const body = new URLSearchParams({ entity, ...this.auth });
        if (entity === 'smart' && this.smartTypeIdEl) {
            body.set('smart_type_id', this.smartTypeIdEl.value || '0');
        }

        try {
            const r = await fetch(this.fieldsApi, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            });
            const d = await r.json();
            if (d.fields && d.fields.length) {
                this.fields = d.fields;
            }
        } catch (_) {
            // Keep fallback fields when the portal field list is unavailable.
        }

        this.fieldsCache[key] = this.fields;
        return this.fields;
    };

    ConditionsForm.prototype.operatorOptions = function (selected) {
        return OPERATORS.map(o => {
            const sel = o.v === selected ? ' selected' : '';
            return `<option value="${o.v}"${sel}>${o.t}</option>`;
        }).join('');
    };

    ConditionsForm.prototype.render = function (conditions) {
        const byField = {};
        (conditions || []).forEach(cond => {
            if (cond && cond.field && !byField[cond.field]) {
                byField[cond.field] = cond;
            }
        });

        this.listEl.innerHTML = '';
        this.fields.forEach(field => {
            const cond = byField[field.code] || {};
            const row = document.createElement('div');
            row.className = 'cf-row cf-row-all';
            row.innerHTML = `
                <label class="cf-field-label">${esc(field.title)} (${esc(field.code)})</label>
                <select class="cf-operator">${this.operatorOptions(cond.operator || 'contains')}</select>
                <input type="text" class="cf-value" data-field="${esc(field.code)}" value="${esc(cond.value || '')}" placeholder="Optional">
            `;
            this.listEl.appendChild(row);
        });
        this.updateCount();
    };

    ConditionsForm.prototype.collect = function () {
        const rows = this.listEl.querySelectorAll('.cf-row');
        const list = [];

        rows.forEach(row => {
            const operator = row.querySelector('.cf-operator').value;
            const input = row.querySelector('.cf-value');
            const value = input.value;

            if (!['empty', 'not_empty'].includes(operator) && value.trim() === '') {
                return;
            }

            list.push({
                field: input.dataset.field,
                operator,
                value,
            });
        });

        return list;
    };

    ConditionsForm.prototype.updateCount = function () {
        if (this.countEl) {
            this.countEl.textContent = 'Filled criteria: ' + this.collect().length + ' / ' + this.fields.length;
        }
    };

    ConditionsForm.prototype.addRow = async function () {
        await this.loadFields();
        this.render(this.collect());
    };

    ConditionsForm.prototype.onEntityChange = async function () {
        if (this.smartRow) {
            this.smartRow.classList.toggle('hidden', !this.entityEl || this.entityEl.value !== 'smart');
        }

        await this.loadFields();
        this.render([]);
    };

    ConditionsForm.prototype.getConditions = function () {
        return this.collect();
    };

    ConditionsForm.prototype.getEntity = function () {
        return this.entityEl ? this.entityEl.value : 'deal';
    };

    ConditionsForm.prototype.init = async function (initial) {
        initial = initial || {};
        if (this.entityEl && initial.entity) {
            this.entityEl.value = initial.entity;
        }
        if (this.smartTypeIdEl && initial.smart_type_id) {
            this.smartTypeIdEl.value = initial.smart_type_id;
        }
        if (this.smartRow && this.entityEl) {
            this.smartRow.classList.toggle('hidden', this.entityEl.value !== 'smart');
        }

        await this.loadFields();
        this.render(initial.conditions || []);
    };

    global.ConditionsForm = ConditionsForm;
})(window);
