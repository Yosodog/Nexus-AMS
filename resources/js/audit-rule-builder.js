const SCHEMA_VERSION = 1;
const MAX_DEPTH = 5;
const MAX_NODES = 100;
const MAX_CHILDREN = 25;
const MAX_MULTI_VALUES = 50;

const GROUP_OPTIONS = [
    { value: 'all', label: 'All conditions match' },
    { value: 'any', label: 'Any condition matches' },
];

const DEFAULT_OPERATOR_LABELS = {
    gt: 'is greater than',
    gte: 'is at least',
    lt: 'is less than',
    lte: 'is at most',
    eq: 'equals',
    neq: 'does not equal',
    between: 'is between',
    not_between: 'is not between',
    multiple_of: 'is a multiple of',
    not_multiple_of: 'is not a multiple of',
    in: 'is one of',
    not_in: 'is not one of',
    is_true: 'is yes',
    is_false: 'is no',
    contains_all: 'contains all of',
    contains_any: 'contains any of',
    contains_none: 'contains none of',
    is_present: 'is available',
    is_missing: 'is missing',
    before: 'is before',
    after: 'is after',
    older_than: 'is older than',
    newer_than: 'is newer than',
};

const VALUELESS_OPERATORS = new Set(['is_true', 'is_false', 'is_present', 'is_missing']);
const RANGE_OPERATORS = new Set(['between', 'not_between']);
const MULTI_OPERATORS = new Set(['in', 'not_in', 'contains_all', 'contains_any', 'contains_none']);
const DURATION_OPERATORS = new Set(['older_than', 'newer_than']);

let instanceCount = 0;

const clone = (value) => JSON.parse(JSON.stringify(value));

const createElement = (tagName, options = {}) => {
    const element = document.createElement(tagName);

    if (options.className) {
        element.className = options.className;
    }

    if (options.text !== undefined) {
        element.textContent = String(options.text);
    }

    Object.entries(options.attributes || {}).forEach(([name, value]) => {
        if (value !== null && value !== undefined) {
            element.setAttribute(name, String(value));
        }
    });

    return element;
};

const parseJson = (value, fallback) => {
    if (typeof value !== 'string' || value.trim() === '') {
        return clone(fallback);
    }

    try {
        return JSON.parse(value);
    } catch {
        return clone(fallback);
    }
};

const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

const uuid = () => {
    if (window.crypto?.randomUUID) {
        return window.crypto.randomUUID();
    }

    const bytes = new Uint8Array(16);

    if (window.crypto?.getRandomValues) {
        window.crypto.getRandomValues(bytes);
    } else {
        bytes.forEach((_, index) => {
            bytes[index] = Math.floor(Math.random() * 256);
        });
    }

    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0'));

    return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10).join('')}`;
};

const booleanValue = (value) => value === true || value === 1 || value === '1' || value === 'true';

const normalizedString = (value) => String(value ?? '').trim();

const optionValue = (option) => String(option?.value ?? option?.key ?? '');

const optionLabel = (option) => String(option?.label ?? option?.name ?? optionValue(option));

class AuditRuleBuilder {
    constructor(root) {
        this.root = root;
        this.instanceId = `audit-rule-builder-${instanceCount += 1}`;
        this.config = this.readConfig();
        this.fields = this.normalizeFields(this.config.fields || []);
        this.fieldMap = new Map(this.fields.map((field) => [field.key, field]));
        this.operatorMap = this.normalizeOperators(this.config.operators || []);
        this.form = root.closest('form');
        this.hiddenInput = root.querySelector('[data-audit-definition-input]');
        this.targetInput = root.querySelector('[data-audit-target]');
        this.enabledInput = root.querySelector('[data-audit-enabled]');
        this.criteriaMount = root.querySelector('[data-audit-criteria]');
        this.exceptionsMount = root.querySelector('[data-audit-exceptions]');
        this.summaryTarget = root.querySelector('[data-audit-summary]');
        this.matchCountTarget = root.querySelector('[data-audit-match-count]');
        this.samplesTarget = root.querySelector('[data-audit-samples]');
        this.warningsTarget = root.querySelector('[data-audit-warnings]');
        this.previewStatusTarget = root.querySelector('[data-audit-preview-status]');
        this.previewDurationTarget = root.querySelector('[data-audit-preview-duration]');
        this.testButton = root.querySelector('[data-audit-test]');
        this.impactDialog = root.querySelector('[data-audit-impact-dialog]');
        this.confirmationInput = root.querySelector('[data-audit-confirmation-token]');
        this.previewUrl = root.dataset.auditPreviewUrl || this.config.preview_url || '';
        this.csrfToken = root.dataset.auditCsrf
            || this.config.csrf_token
            || this.form?.querySelector('input[name="_token"]')?.value
            || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
            || '';
        this.renderVersion = 0;
        this.errors = new Map();
        this.previewController = null;
        this.previewState = null;
        this.pendingSubmitter = null;
        this.allowNextSubmit = false;
        this.exceptionsExpanded = false;
        this.previousTarget = this.targetInput?.value || this.config.target || 'nation';

        this.assertRequiredMarkup();

        this.definition = this.normalizeDefinition(this.readInitialDefinition());
        this.originalDefinition = this.normalizeDefinition(this.config.original_definition || this.definition);
        this.originalTarget = this.config.original_target || this.previousTarget;
        this.originalEnabled = this.config.original_enabled !== undefined
            ? booleanValue(this.config.original_enabled)
            : Boolean(this.enabledInput.checked);
        this.originalBehavior = this.behaviorSignature(this.originalDefinition, this.originalTarget);
        this.exceptionsExpanded = this.definition.exceptions.rules.length > 0;

        this.ensureConfirmationInput();
        this.createFieldPicker();
        this.bindEvents();
        this.render();
    }

    readConfig() {
        const script = this.root.querySelector('[data-audit-rule-config]');
        const raw = script?.textContent || this.root.dataset.auditRuleConfig || '{}';

        return parseJson(raw, {});
    }

    readInitialDefinition() {
        if (this.hiddenInput.value.trim() !== '') {
            return parseJson(this.hiddenInput.value, this.emptyDefinition());
        }

        return this.config.definition || this.config.default_definition || this.emptyDefinition();
    }

    emptyDefinition() {
        return {
            schema_version: SCHEMA_VERSION,
            criteria: { group: 'all', rules: [] },
            exceptions: { group: 'any', rules: [] },
        };
    }

    normalizeFields(fields) {
        const source = Array.isArray(fields)
            ? fields
            : Object.entries(fields).map(([key, descriptor]) => ({ key, ...descriptor }));

        return source
            .filter((field) => field && (field.key || field.value))
            .map((field) => ({
                ...field,
                key: String(field.key || field.value),
                label: String(field.label || field.name || field.key || field.value),
                category: String(field.category || 'General'),
                type: this.normalizeFieldType(field.type),
                operators: (field.operators || []).map((operator) => (
                    typeof operator === 'string' ? operator : operator.value
                )).filter(Boolean),
                options: (field.options || []).map((option) => (
                    typeof option === 'object' ? option : { value: option, label: option }
                )),
            }));
    }

    normalizeFieldType(type) {
        const normalized = String(type || 'text').toLowerCase();

        if (['integer', 'decimal', 'float'].includes(normalized)) {
            return 'number';
        }

        if (['multi', 'multiselect', 'collection'].includes(normalized)) {
            return 'multi';
        }

        if (normalized === 'project') {
            return 'project';
        }

        if (['date', 'timestamp'].includes(normalized)) {
            return 'datetime';
        }

        return normalized;
    }

    normalizeOperators(operators) {
        const source = Array.isArray(operators)
            ? operators
            : Object.entries(operators).map(([value, descriptor]) => (
                typeof descriptor === 'string' ? { value, label: descriptor } : { value, ...descriptor }
            ));
        const map = new Map();

        Object.entries(DEFAULT_OPERATOR_LABELS).forEach(([value, label]) => {
            map.set(value, { value, label });
        });

        source.forEach((operator) => {
            const value = String(operator?.value || operator?.key || '');

            if (value !== '') {
                map.set(value, {
                    ...operator,
                    value,
                    label: String(operator.label || DEFAULT_OPERATOR_LABELS[value] || value),
                });
            }
        });

        return map;
    }

    assertRequiredMarkup() {
        if (
            !(this.hiddenInput instanceof HTMLInputElement)
            || !(this.targetInput instanceof HTMLSelectElement)
            || !(this.enabledInput instanceof HTMLInputElement)
            || !this.criteriaMount
            || !this.exceptionsMount
        ) {
            throw new Error('Audit rule builder markup is incomplete.');
        }
    }

    ensureConfirmationInput() {
        if (this.confirmationInput instanceof HTMLInputElement) {
            return;
        }

        this.confirmationInput = createElement('input', {
            attributes: {
                type: 'hidden',
                name: this.config.confirmation_token_name || 'impact_confirmation_token',
                'data-audit-confirmation-token': '',
            },
        });
        this.root.appendChild(this.confirmationInput);
    }

    bindEvents() {
        this.targetInput.addEventListener('change', () => this.handleTargetChange());
        this.enabledInput.addEventListener('change', () => {
            this.clearConfirmation();
            this.validateAndRenderErrors(false);
            this.updateSummary();
        });

        this.testButton?.addEventListener('click', async () => {
            await this.runPreview({ forActivation: false });
        });

        this.form?.addEventListener('submit', (event) => this.handleSubmit(event));
        this.bindImpactDialog();
    }

    bindImpactDialog() {
        if (!(this.impactDialog instanceof HTMLDialogElement)) {
            return;
        }

        this.impactDialog.querySelector('[data-audit-impact-confirm]')?.addEventListener('click', () => {
            const token = this.previewState?.confirmationToken;

            if (!token) {
                this.setPreviewStatus('Impact confirmation is unavailable. Test the rule again.', 'error');
                return;
            }

            this.confirmationInput.value = token;
            this.allowNextSubmit = true;
            this.impactDialog.close('confirmed');

            if (this.form) {
                this.form.dataset.asyncPending = 'false';
                this.form.removeAttribute('aria-busy');

                if (this.pendingSubmitter instanceof HTMLElement) {
                    this.pendingSubmitter.removeAttribute('aria-disabled');
                    this.pendingSubmitter.dataset.asyncBusy = 'false';
                }

                this.form.requestSubmit(this.pendingSubmitter || undefined);
            }
        });

        this.impactDialog.querySelector('[data-audit-impact-cancel]')?.addEventListener('click', () => {
            this.impactDialog.close('cancelled');
            this.pendingSubmitter = null;
        });

        this.impactDialog.addEventListener('cancel', () => {
            this.pendingSubmitter = null;
        });
    }

    handleTargetChange() {
        const nextTarget = this.targetInput.value;

        if (nextTarget === this.previousTarget) {
            return;
        }

        const accepted = window.confirm(
            'Changing the target can remove conditions that are not available for the new target. Continue?',
        );

        if (!accepted) {
            this.targetInput.value = this.previousTarget;
            return;
        }

        this.previousTarget = nextTarget;
        this.definition.criteria = this.removeIncompatibleNodes(this.definition.criteria, nextTarget);
        this.definition.exceptions = this.removeIncompatibleNodes(this.definition.exceptions, nextTarget);
        this.clearConfirmation();
        this.render();
        this.announce('Target changed. Incompatible conditions were removed.');
    }

    removeIncompatibleNodes(group, target) {
        return {
            ...group,
            rules: group.rules.map((node) => {
                if (node.group) {
                    return this.removeIncompatibleNodes(node, target);
                }

                return this.isFieldCompatible(this.fieldMap.get(node.field), target) ? node : null;
            }).filter((node) => node && (!node.group || node.rules.length > 0)),
        };
    }

    isFieldCompatible(field, target = this.targetInput.value) {
        if (!field) {
            return false;
        }

        const targets = field.targets || (field.target ? [field.target] : []);

        return targets.length === 0 || targets.includes(target) || targets.includes('both');
    }

    normalizeDefinition(definition) {
        const source = definition && typeof definition === 'object' && !Array.isArray(definition)
            ? definition
            : this.emptyDefinition();
        const usedIds = new Set();

        return {
            schema_version: SCHEMA_VERSION,
            criteria: this.normalizeGroup(source.criteria, 'all', usedIds, true),
            exceptions: this.normalizeGroup(source.exceptions, 'any', usedIds, true),
        };
    }

    normalizeGroup(group, fallbackMode, usedIds, isRoot = false) {
        const source = group && typeof group === 'object' && !Array.isArray(group) ? group : {};
        const normalized = {
            group: ['all', 'any'].includes(source.group) ? source.group : fallbackMode,
            rules: [],
        };

        if (!isRoot) {
            normalized.id = this.uniqueId(source.id, usedIds);
        }

        normalized.rules = (Array.isArray(source.rules) ? source.rules : [])
            .map((node) => this.normalizeNode(node, usedIds))
            .filter(Boolean);

        return normalized;
    }

    normalizeNode(node, usedIds) {
        if (!node || typeof node !== 'object' || Array.isArray(node)) {
            return null;
        }

        if (Object.prototype.hasOwnProperty.call(node, 'group')) {
            return this.normalizeGroup(node, 'all', usedIds);
        }

        const field = this.fieldMap.get(String(node.field || ''));

        if (!field) {
            return null;
        }

        const operator = field.operators.includes(node.operator) ? node.operator : field.operators[0];

        if (!operator) {
            return null;
        }

        return {
            id: this.uniqueId(node.id, usedIds),
            field: field.key,
            operator,
            value: this.normalizeValue(field, operator, node.value),
        };
    }

    uniqueId(candidate, usedIds) {
        let id = normalizedString(candidate);

        if (!UUID_PATTERN.test(id) || usedIds.has(id)) {
            id = uuid();
        }

        usedIds.add(id);
        return id;
    }

    normalizeValue(field, operator, value) {
        if (VALUELESS_OPERATORS.has(operator)) {
            return null;
        }

        if (RANGE_OPERATORS.has(operator)) {
            if (Array.isArray(value)) {
                return { min: value[0] ?? '', max: value[1] ?? '' };
            }

            return { min: value?.min ?? '', max: value?.max ?? '' };
        }

        if (MULTI_OPERATORS.has(operator) || ['multi', 'project'].includes(field.type)) {
            return Array.isArray(value) ? value.map(String) : [];
        }

        if (DURATION_OPERATORS.has(operator) || field.type === 'duration') {
            return {
                amount: value?.amount ?? value?.value ?? '',
                unit: ['hours', 'days', 'weeks', 'months'].includes(value?.unit) ? value.unit : 'days',
            };
        }

        if (field.type === 'boolean') {
            return Boolean(value);
        }

        return value ?? '';
    }

    createEmptyCondition() {
        const field = this.availableFields()[0] || this.fields[0];

        if (!field) {
            return null;
        }

        const operator = field.operators[0];

        return {
            id: uuid(),
            field: field.key,
            operator,
            value: this.defaultValue(field, operator),
        };
    }

    createEmptyGroup() {
        return { id: uuid(), group: 'all', rules: [] };
    }

    defaultValue(field, operator) {
        if (VALUELESS_OPERATORS.has(operator)) {
            return null;
        }

        if (RANGE_OPERATORS.has(operator)) {
            return { min: '', max: '' };
        }

        if (MULTI_OPERATORS.has(operator) || ['multi', 'project'].includes(field.type)) {
            return [];
        }

        if (DURATION_OPERATORS.has(operator) || field.type === 'duration') {
            return { amount: '', unit: 'days' };
        }

        if (field.type === 'boolean') {
            return true;
        }

        if (field.type === 'enum') {
            return optionValue(field.options[0]);
        }

        return '';
    }

    availableFields() {
        return this.fields.filter((field) => this.isFieldCompatible(field));
    }

    render() {
        this.renderVersion += 1;
        this.errors.clear();
        this.criteriaMount.replaceChildren(this.renderTree('criteria'));
        this.exceptionsMount.replaceChildren(this.renderExceptions());
        this.syncHiddenInput();
        this.updateSummary();
        this.validateAndRenderErrors(false);
    }

    renderTree(treeName) {
        const group = this.definition[treeName];
        const wrapper = createElement('div', { className: 'grid gap-4' });
        wrapper.appendChild(this.renderGroupLogic(group, treeName, [], true));

        const children = createElement('div', { className: 'grid gap-3' });
        children.setAttribute('role', 'list');
        group.rules.forEach((node, index) => {
            children.appendChild(this.renderNode(node, treeName, [index], 1));
        });

        if (group.rules.length === 0) {
            children.appendChild(this.renderEmptyState(
                treeName === 'criteria'
                    ? 'Add a condition to describe when this rule should create a finding.'
                    : 'No exceptions are configured.',
            ));
        }

        wrapper.appendChild(children);
        wrapper.appendChild(this.renderAddActions(treeName, [], 1));

        return wrapper;
    }

    renderExceptions() {
        const wrapper = createElement('div', { className: 'grid gap-4' });
        const hasRules = this.definition.exceptions.rules.length > 0;

        if (!this.exceptionsExpanded && !hasRules) {
            const button = this.button('Add an exception', 'btn btn-outline min-h-11 w-full sm:w-auto', () => {
                this.exceptionsExpanded = true;
                const condition = this.createEmptyCondition();

                if (condition) {
                    this.definition.exceptions.rules.push(condition);
                }

                this.clearConfirmation();
                this.render();
                this.focusNode(condition?.id);
            });
            button.setAttribute('aria-expanded', 'false');
            wrapper.appendChild(button);

            const hint = createElement('p', {
                className: 'text-sm leading-5 text-base-content/60',
                text: 'Use exceptions only when a matching target should be deliberately suppressed.',
            });
            wrapper.appendChild(hint);
            return wrapper;
        }

        const collapse = this.button('Hide exceptions', 'btn btn-ghost min-h-11 w-full sm:w-auto', () => {
            if (this.definition.exceptions.rules.length > 0) {
                const accepted = window.confirm('Remove all exception conditions from this rule?');

                if (!accepted) {
                    return;
                }

                this.definition.exceptions.rules = [];
            }

            this.exceptionsExpanded = false;
            this.clearConfirmation();
            this.render();
        });
        collapse.setAttribute('aria-expanded', 'true');
        wrapper.appendChild(collapse);
        wrapper.appendChild(this.renderTree('exceptions'));

        return wrapper;
    }

    renderGroupLogic(group, treeName, path, isRoot = false) {
        const row = createElement('div', {
            className: 'flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between',
        });
        const sentence = createElement('p', {
            className: 'text-base leading-6 text-base-content',
            text: treeName === 'criteria'
                ? 'Create a finding when'
                : 'Suppress the finding when',
        });
        row.appendChild(sentence);

        const select = createElement('select', {
            className: 'select min-h-11 w-full sm:w-auto',
            attributes: {
                'aria-label': isRoot
                    ? `${treeName === 'criteria' ? 'Criteria' : 'Exception'} matching logic`
                    : 'Nested group matching logic',
            },
        });
        GROUP_OPTIONS.forEach((optionData) => {
            const option = createElement('option', { text: optionData.label });
            option.value = optionData.value;
            option.selected = group.group === optionData.value;
            select.appendChild(option);
        });
        select.addEventListener('change', () => {
            group.group = select.value;
            this.handleDefinitionChange();
        });
        row.appendChild(select);

        if (!isRoot) {
            row.appendChild(this.renderNodeActions(treeName, path, group.id));
        }

        return row;
    }

    renderEmptyState(message) {
        return createElement('p', {
            className: 'border-l border-base-300 py-3 pl-4 text-sm leading-5 text-base-content/60',
            text: message,
            attributes: { role: 'status' },
        });
    }

    renderNode(node, treeName, path, depth) {
        return node.group
            ? this.renderNestedGroup(node, treeName, path, depth)
            : this.renderCondition(node, treeName, path);
    }

    renderNestedGroup(group, treeName, path, depth) {
        const section = createElement('section', {
            className: 'grid gap-3 border-l border-base-300 py-2 pl-4 sm:pl-6',
            attributes: {
                'data-node-id': group.id,
                role: 'listitem',
                'aria-label': `Nested ${group.group === 'all' ? 'all' : 'any'} group`,
            },
        });
        section.appendChild(this.renderGroupLogic(group, treeName, path));

        const children = createElement('div', { className: 'grid gap-3', attributes: { role: 'list' } });
        group.rules.forEach((child, index) => {
            children.appendChild(this.renderNode(child, treeName, [...path, index], depth + 1));
        });

        if (group.rules.length === 0) {
            children.appendChild(this.renderEmptyState('This group is empty. Add a condition or subgroup.'));
        }

        section.appendChild(children);
        section.appendChild(this.renderAddActions(treeName, path, depth + 1));
        section.appendChild(this.renderInlineErrors(group.id));
        return section;
    }

    renderCondition(condition, treeName, path) {
        const field = this.fieldMap.get(condition.field);
        const row = createElement('div', {
            className: 'audit-rule-condition-row grid gap-3 border-b border-base-300 py-4 last:border-b-0',
            attributes: {
                'data-node-id': condition.id,
                role: 'listitem',
            },
        });

        row.appendChild(this.createFieldControl(field, condition, treeName, path));
        row.appendChild(this.createOperatorControl(field, condition));
        row.appendChild(this.createValueControl(field, condition));
        row.appendChild(this.renderNodeActions(treeName, path, condition.id));

        const errors = this.renderInlineErrors(condition.id);
        errors.classList.add('lg:col-span-4');
        row.appendChild(errors);
        return row;
    }

    createFieldControl(field, condition) {
        const wrapper = this.controlWrapper('Field');
        const button = this.button(
            field?.label || 'Choose a field',
            'btn btn-outline min-h-11 w-full justify-between text-left font-medium',
            () => this.openFieldPicker(condition.id),
        );
        button.id = this.controlId(condition.id, 'field');
        button.setAttribute('role', 'combobox');
        button.setAttribute('aria-haspopup', 'dialog');
        button.setAttribute('aria-expanded', 'false');
        button.dataset.auditFieldButton = condition.id;
        wrapper.querySelector('label').htmlFor = button.id;
        wrapper.appendChild(button);
        return wrapper;
    }

    createOperatorControl(field, condition) {
        const select = createElement('select', {
            className: 'select min-h-11 w-full',
            attributes: { 'aria-label': `Operator for ${field?.label || 'condition'}` },
        });
        (field?.operators || []).forEach((operatorKey) => {
            const descriptor = this.operatorMap.get(operatorKey);
            const option = createElement('option', {
                text: descriptor?.label || DEFAULT_OPERATOR_LABELS[operatorKey] || operatorKey,
            });
            option.value = operatorKey;
            option.selected = condition.operator === operatorKey;
            select.appendChild(option);
        });
        select.addEventListener('change', () => {
            condition.operator = select.value;
            condition.value = this.defaultValue(field, condition.operator);
            this.handleDefinitionChange(true, condition.id);
        });

        return this.labeledControl('Condition', select, condition.id, 'operator');
    }

    createValueControl(field, condition) {
        const wrapper = this.controlWrapper('Value');
        const operator = condition.operator;

        if (VALUELESS_OPERATORS.has(operator)) {
            const note = createElement('div', {
                className: 'flex min-h-11 items-center text-sm text-base-content/60',
                text: 'No value needed',
                attributes: { 'aria-live': 'polite' },
            });
            wrapper.appendChild(note);
            return wrapper;
        }

        if (RANGE_OPERATORS.has(operator)) {
            const range = createElement('div', { className: 'grid grid-cols-2 gap-2' });
            range.appendChild(this.numberInput(condition.value?.min ?? '', 'Minimum value', (value) => {
                condition.value = { min: value, max: condition.value?.max ?? '' };
                this.handleDefinitionChange();
            }));
            range.appendChild(this.numberInput(condition.value?.max ?? '', 'Maximum value', (value) => {
                condition.value = { min: condition.value?.min ?? '', max: value };
                this.handleDefinitionChange();
            }));
            wrapper.appendChild(range);
            return wrapper;
        }

        if (DURATION_OPERATORS.has(operator) || field?.type === 'duration') {
            const duration = createElement('div', { className: 'audit-rule-value-chooser grid gap-2' });
            duration.appendChild(this.numberInput(
                condition.value?.amount ?? '',
                'Duration amount',
                (value) => {
                    condition.value = { ...condition.value, amount: value };
                    this.handleDefinitionChange();
                },
                { min: '1', step: '1' },
            ));
            const unit = createElement('select', {
                className: 'select min-h-11',
                attributes: { 'aria-label': 'Duration unit' },
            });
            ['hours', 'days', 'weeks', 'months'].forEach((value) => {
                const option = createElement('option', { text: value[0].toUpperCase() + value.slice(1) });
                option.value = value;
                option.selected = condition.value?.unit === value;
                unit.appendChild(option);
            });
            unit.addEventListener('change', () => {
                condition.value = { ...condition.value, unit: unit.value };
                this.handleDefinitionChange();
            });
            duration.appendChild(unit);
            wrapper.appendChild(duration);
            return wrapper;
        }

        if (MULTI_OPERATORS.has(operator) || ['multi', 'project'].includes(field?.type)) {
            wrapper.appendChild(this.createMultiSelect(field, condition));
            return wrapper;
        }

        if (field?.type === 'enum') {
            const select = createElement('select', {
                className: 'select min-h-11 w-full',
                attributes: { 'aria-label': `Value for ${field.label}` },
            });
            field.options.forEach((optionData) => {
                const option = createElement('option', { text: optionLabel(optionData) });
                option.value = optionValue(optionData);
                option.selected = String(condition.value) === option.value;
                select.appendChild(option);
            });
            select.addEventListener('change', () => {
                condition.value = select.value;
                this.handleDefinitionChange();
            });
            wrapper.appendChild(select);
            return wrapper;
        }

        if (field?.type === 'boolean') {
            const select = createElement('select', {
                className: 'select min-h-11 w-full',
                attributes: { 'aria-label': `Value for ${field.label}` },
            });
            [{ value: 'true', label: 'Yes' }, { value: 'false', label: 'No' }].forEach((item) => {
                const option = createElement('option', { text: item.label });
                option.value = item.value;
                option.selected = Boolean(condition.value) === (item.value === 'true');
                select.appendChild(option);
            });
            select.addEventListener('change', () => {
                condition.value = select.value === 'true';
                this.handleDefinitionChange();
            });
            wrapper.appendChild(select);
            return wrapper;
        }

        if (field?.type === 'datetime') {
            const input = createElement('input', {
                className: 'input min-h-11 w-full',
                attributes: { type: 'date', 'aria-label': `Date for ${field.label}` },
            });
            input.value = normalizedString(condition.value).slice(0, 10);
            input.addEventListener('input', () => {
                condition.value = input.value;
                this.handleDefinitionChange();
            });
            wrapper.appendChild(input);
            return wrapper;
        }

        if (field?.type === 'number' || field?.type === 'range') {
            wrapper.appendChild(this.numberInput(condition.value, `Value for ${field.label}`, (value) => {
                condition.value = value;
                this.handleDefinitionChange();
            }, {
                step: field.step || 'any',
                min: field.min,
                max: field.max,
            }));
            return wrapper;
        }

        const input = createElement('input', {
            className: 'input min-h-11 w-full',
            attributes: {
                type: 'text',
                maxlength: field?.max_length || 255,
                'aria-label': `Value for ${field?.label || 'condition'}`,
            },
        });
        input.value = normalizedString(condition.value);
        input.addEventListener('input', () => {
            condition.value = input.value;
            this.handleDefinitionChange();
        });
        wrapper.appendChild(input);
        return wrapper;
    }

    createMultiSelect(field, condition) {
        const container = createElement('div', { className: 'grid gap-2' });
        const chooser = createElement('div', { className: 'audit-rule-value-chooser grid gap-2' });
        const input = createElement('input', {
            className: 'input min-h-11 w-full',
            attributes: {
                type: 'search',
                placeholder: field.options.length > 0 ? 'Search or choose a value' : 'Enter a value',
                autocomplete: 'off',
                'aria-label': `Add a value for ${field.label}`,
            },
        });
        const selected = new Set(Array.isArray(condition.value) ? condition.value.map(String) : []);

        if (field.options.length > 0) {
            const listId = `${this.controlId(condition.id, 'values')}-list`;
            const dataList = createElement('datalist', { attributes: { id: listId } });
            field.options.forEach((optionData) => {
                const value = optionValue(optionData);

                if (!selected.has(value)) {
                    dataList.appendChild(createElement('option', {
                        attributes: { value: optionLabel(optionData), 'data-value': value },
                    }));
                }
            });
            input.setAttribute('list', listId);
            container.appendChild(dataList);
        }

        const addValue = () => {
            const candidate = normalizedString(input.value);

            if (candidate === '' || selected.size >= MAX_MULTI_VALUES) {
                return;
            }

            const matchedOption = field.options.find((option) => (
                optionValue(option).toLowerCase() === candidate.toLowerCase()
                || optionLabel(option).toLowerCase() === candidate.toLowerCase()
            ));
            const value = matchedOption ? optionValue(matchedOption) : (field.options.length === 0 ? candidate : '');

            if (value === '' || selected.has(value)) {
                return;
            }

            condition.value = [...selected, value];
            this.handleDefinitionChange(true, condition.id);
        };
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                addValue();
            }
        });
        chooser.appendChild(input);
        chooser.appendChild(this.button('Add', 'btn btn-outline min-h-11', addValue));
        container.appendChild(chooser);

        const chips = createElement('div', {
            className: 'flex min-h-8 flex-wrap gap-2',
            attributes: { 'aria-label': `Selected values for ${field.label}` },
        });
        selected.forEach((value) => {
            const option = field.options.find((item) => optionValue(item) === value);
            const chip = createElement('span', { className: 'badge badge-outline min-h-8 gap-1' });
            chip.appendChild(createElement('span', { text: option ? optionLabel(option) : value }));
            const remove = this.button('Remove', 'btn btn-ghost btn-xs min-h-8 px-2', () => {
                condition.value = [...selected].filter((selectedValue) => selectedValue !== value);
                this.handleDefinitionChange(true, condition.id);
            });
            remove.setAttribute('aria-label', `Remove ${option ? optionLabel(option) : value}`);
            chip.appendChild(remove);
            chips.appendChild(chip);
        });

        if (selected.size === 0) {
            chips.appendChild(createElement('span', {
                className: 'text-sm text-base-content/60',
                text: 'No values selected',
            }));
        }

        container.appendChild(chips);
        return container;
    }

    numberInput(value, ariaLabel, onInput, constraints = {}) {
        const input = createElement('input', {
            className: 'input min-h-11 w-full',
            attributes: {
                type: 'number',
                step: constraints.step || 'any',
                min: constraints.min,
                max: constraints.max,
                'aria-label': ariaLabel,
            },
        });
        input.value = value ?? '';
        input.addEventListener('input', () => onInput(input.value));
        return input;
    }

    controlWrapper(labelText) {
        const wrapper = createElement('div', { className: 'grid gap-2' });
        wrapper.appendChild(createElement('label', {
            className: 'text-sm font-semibold leading-5 text-base-content',
            text: labelText,
        }));
        return wrapper;
    }

    labeledControl(labelText, control, nodeId, suffix) {
        const wrapper = this.controlWrapper(labelText);
        const id = this.controlId(nodeId, suffix);
        wrapper.querySelector('label').htmlFor = id;
        control.id = id;
        wrapper.appendChild(control);
        return wrapper;
    }

    controlId(nodeId, suffix) {
        return `${this.instanceId}-${this.renderVersion}-${nodeId}-${suffix}`;
    }

    renderNodeActions(treeName, path, nodeId) {
        const actions = createElement('div', {
            className: 'flex flex-wrap items-center gap-2 lg:justify-end',
            attributes: { 'aria-label': 'Condition actions' },
        });
        const parent = this.getGroup(treeName, path.slice(0, -1));
        const index = path[path.length - 1];
        const up = this.button('Move up', 'btn btn-ghost min-h-11', () => {
            this.moveNode(treeName, path, -1);
            this.render();
            this.focusNode(nodeId);
        });
        up.disabled = index <= 0;
        actions.appendChild(up);

        const down = this.button('Move down', 'btn btn-ghost min-h-11', () => {
            this.moveNode(treeName, path, 1);
            this.render();
            this.focusNode(nodeId);
        });
        down.disabled = index >= parent.rules.length - 1;
        actions.appendChild(down);

        actions.appendChild(this.button('Remove', 'btn btn-ghost min-h-11 text-error', () => {
            this.removeNode(treeName, path);
            this.clearConfirmation();
            this.render();
            this.announce('Condition removed.');
        }));
        return actions;
    }

    renderAddActions(treeName, groupPath, childDepth) {
        const actions = createElement('div', { className: 'flex flex-col gap-2 sm:flex-row' });
        const group = this.getGroup(treeName, groupPath);
        const atChildLimit = group.rules.length >= MAX_CHILDREN;
        const atNodeLimit = this.countNodes() >= MAX_NODES;

        const addCondition = this.button(
            treeName === 'exceptions' ? 'Add exception condition' : 'Add condition',
            'btn btn-outline btn-primary min-h-11 w-full sm:w-auto',
            () => {
                const condition = this.createEmptyCondition();

                if (!condition) {
                    this.announce('No fields are available for this target.');
                    return;
                }

                group.rules.push(condition);
                this.clearConfirmation();
                this.render();
                this.focusNode(condition.id);
            },
        );
        addCondition.disabled = atChildLimit || atNodeLimit;
        actions.appendChild(addCondition);

        const addGroup = this.button('Add subgroup', 'btn btn-ghost min-h-11 w-full sm:w-auto', () => {
            const nested = this.createEmptyGroup();
            group.rules.push(nested);
            this.clearConfirmation();
            this.render();
            this.focusNode(nested.id);
        });
        addGroup.disabled = atChildLimit || atNodeLimit || childDepth >= MAX_DEPTH;
        actions.appendChild(addGroup);

        if (atChildLimit || atNodeLimit || childDepth >= MAX_DEPTH) {
            const reason = atNodeLimit
                ? `This rule has reached the ${MAX_NODES}-node limit.`
                : atChildLimit
                    ? `This group has reached the ${MAX_CHILDREN}-item limit.`
                    : `Groups can be nested up to ${MAX_DEPTH} levels.`;
            actions.appendChild(createElement('p', {
                className: 'self-center text-sm text-warning',
                text: reason,
                attributes: { role: 'status' },
            }));
        }

        return actions;
    }

    button(label, className, handler) {
        const button = createElement('button', {
            className,
            text: label,
            attributes: { type: 'button' },
        });
        button.addEventListener('click', handler);
        return button;
    }

    getGroup(treeName, path) {
        let group = this.definition[treeName];

        path.forEach((index) => {
            group = group.rules[index];
        });

        return group;
    }

    removeNode(treeName, path) {
        const parent = this.getGroup(treeName, path.slice(0, -1));
        parent.rules.splice(path[path.length - 1], 1);
    }

    moveNode(treeName, path, offset) {
        const parent = this.getGroup(treeName, path.slice(0, -1));
        const index = path[path.length - 1];
        const destination = index + offset;

        if (destination < 0 || destination >= parent.rules.length) {
            return;
        }

        const [node] = parent.rules.splice(index, 1);
        parent.rules.splice(destination, 0, node);
        this.clearConfirmation();
    }

    focusNode(nodeId) {
        window.requestAnimationFrame(() => {
            const node = [...this.root.querySelectorAll('[data-node-id]')]
                .find((element) => element.dataset.nodeId === nodeId);
            node?.querySelector('button, select, input')?.focus({ preventScroll: false });
        });
    }

    createFieldPicker() {
        this.fieldDialog = createElement('dialog', {
            className: 'modal',
            attributes: {
                'aria-labelledby': `${this.instanceId}-field-picker-title`,
                'data-audit-field-picker': '',
            },
        });
        const panel = createElement('div', { className: 'audit-rule-field-picker modal-box grid gap-4 p-6' });
        const title = createElement('h3', {
            className: 'text-xl font-semibold',
            text: 'Choose a field',
            attributes: { id: `${this.instanceId}-field-picker-title` },
        });
        panel.appendChild(title);

        this.fieldSearch = createElement('input', {
            className: 'input min-h-11 w-full',
            attributes: {
                type: 'search',
                placeholder: 'Search fields',
                autocomplete: 'off',
                'aria-label': 'Search audit fields',
            },
        });
        panel.appendChild(this.fieldSearch);
        this.fieldResults = createElement('div', {
            className: 'audit-rule-field-picker__results grid min-h-0 gap-4 pr-1',
            attributes: { role: 'listbox', 'aria-label': 'Audit fields' },
        });
        panel.appendChild(this.fieldResults);

        const actions = createElement('div', { className: 'modal-action mt-0' });
        actions.appendChild(this.button('Cancel', 'btn min-h-11', () => this.closeFieldPicker()));
        panel.appendChild(actions);
        this.fieldDialog.appendChild(panel);
        this.root.appendChild(this.fieldDialog);

        this.fieldSearch.addEventListener('input', () => this.renderFieldPickerResults());
        this.fieldSearch.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                this.fieldResults.querySelector('button:not([hidden])')?.focus();
            }
        });
        this.fieldDialog.addEventListener('close', () => {
            const button = this.root.querySelector(`[data-audit-field-button="${this.activeFieldNodeId}"]`);
            button?.setAttribute('aria-expanded', 'false');
            button?.focus();
        });
    }

    openFieldPicker(nodeId) {
        this.activeFieldNodeId = nodeId;
        this.fieldSearch.value = '';
        this.renderFieldPickerResults();
        this.root.querySelector(`[data-audit-field-button="${nodeId}"]`)?.setAttribute('aria-expanded', 'true');

        if (typeof this.fieldDialog.showModal === 'function') {
            this.fieldDialog.showModal();
        } else {
            this.fieldDialog.setAttribute('open', '');
        }

        window.requestAnimationFrame(() => this.fieldSearch.focus());
    }

    closeFieldPicker() {
        if (typeof this.fieldDialog.close === 'function') {
            this.fieldDialog.close();
        } else {
            this.fieldDialog.removeAttribute('open');
        }
    }

    renderFieldPickerResults() {
        const query = this.fieldSearch.value.trim().toLowerCase();
        const grouped = new Map();
        this.availableFields().filter((field) => (
            query === ''
            || field.label.toLowerCase().includes(query)
            || field.category.toLowerCase().includes(query)
            || normalizedString(field.description).toLowerCase().includes(query)
        )).forEach((field) => {
            if (!grouped.has(field.category)) {
                grouped.set(field.category, []);
            }

            grouped.get(field.category).push(field);
        });
        this.fieldResults.replaceChildren();

        if (grouped.size === 0) {
            this.fieldResults.appendChild(createElement('p', {
                className: 'py-6 text-center text-sm text-base-content/60',
                text: 'No fields match that search.',
                attributes: { role: 'status' },
            }));
            return;
        }

        grouped.forEach((fields, category) => {
            const section = createElement('section', { className: 'grid gap-2' });
            section.appendChild(createElement('h4', {
                className: 'text-sm font-semibold text-base-content/70',
                text: category,
            }));
            const list = createElement('div', { className: 'grid gap-1' });
            fields.forEach((field) => {
                const button = this.button(field.label, 'btn btn-ghost min-h-11 justify-start text-left', () => {
                    this.selectField(field.key);
                });
                button.setAttribute('role', 'option');
                button.addEventListener('keydown', (event) => this.handleFieldOptionKeydown(event));
                list.appendChild(button);
            });
            section.appendChild(list);
            this.fieldResults.appendChild(section);
        });
    }

    handleFieldOptionKeydown(event) {
        if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
            return;
        }

        event.preventDefault();
        const options = [...this.fieldResults.querySelectorAll('button')];
        const current = options.indexOf(event.currentTarget);
        const destination = event.key === 'Home'
            ? 0
            : event.key === 'End'
                ? options.length - 1
                : Math.max(0, Math.min(options.length - 1, current + (event.key === 'ArrowDown' ? 1 : -1)));
        options[destination]?.focus();
    }

    selectField(fieldKey) {
        const found = this.findNodeById(this.activeFieldNodeId);
        const field = this.fieldMap.get(fieldKey);

        if (!found || !field) {
            return;
        }

        found.node.field = field.key;
        found.node.operator = field.operators[0];
        found.node.value = this.defaultValue(field, found.node.operator);
        this.closeFieldPicker();
        this.handleDefinitionChange(true, found.node.id);
    }

    findNodeById(nodeId) {
        const search = (group) => {
            for (let index = 0; index < group.rules.length; index += 1) {
                const node = group.rules[index];

                if (node.id === nodeId) {
                    return { node, parent: group, index };
                }

                if (node.group) {
                    const found = search(node);

                    if (found) {
                        return found;
                    }
                }
            }

            return null;
        };

        return search(this.definition.criteria) || search(this.definition.exceptions);
    }

    handleDefinitionChange(shouldRender = false, focusNodeId = null) {
        this.clearConfirmation();
        this.syncHiddenInput();
        this.updateSummary();
        this.validateAndRenderErrors(false);

        if (shouldRender) {
            this.render();

            if (focusNodeId) {
                this.focusNode(focusNodeId);
            }
        }
    }

    syncHiddenInput() {
        this.hiddenInput.value = JSON.stringify(this.definition);
        this.hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
    }

    updateSummary() {
        if (!this.summaryTarget) {
            return;
        }

        const criteria = this.summarizeGroup(this.definition.criteria);
        const exceptions = this.definition.exceptions.rules.length > 0
            ? ` Except when ${this.summarizeGroup(this.definition.exceptions).replace(/^all of: |^any of: /i, '')}.`
            : '';

        this.summaryTarget.textContent = this.definition.criteria.rules.length > 0
            ? `Create a finding when ${criteria.replace(/^all of: |^any of: /i, '')}.${exceptions}`
            : 'Add a condition to generate a plain-language rule summary.';
    }

    summarizeGroup(group) {
        const prefix = group.group === 'all' ? 'All of' : 'Any of';
        const summaries = group.rules.map((node) => this.summarizeNode(node));
        return `${prefix}: ${summaries.join(group.group === 'all' ? '; and ' : '; or ')}`;
    }

    summarizeNode(node) {
        if (node.group) {
            return `(${this.summarizeGroup(node)})`;
        }

        const field = this.fieldMap.get(node.field);
        const operator = this.operatorMap.get(node.operator);
        return `${field?.label || node.field} ${operator?.label || node.operator}${this.formatSummaryValue(field, node)}`;
    }

    formatSummaryValue(field, node) {
        if (VALUELESS_OPERATORS.has(node.operator)) {
            return '';
        }

        if (RANGE_OPERATORS.has(node.operator)) {
            return ` ${node.value?.min || '…'} and ${node.value?.max || '…'}${field?.unit ? ` ${field.unit}` : ''}`;
        }

        if (DURATION_OPERATORS.has(node.operator) || field?.type === 'duration') {
            return ` ${node.value?.amount || '…'} ${node.value?.unit || 'days'}`;
        }

        if (Array.isArray(node.value)) {
            const labels = node.value.map((value) => this.labelForValue(field, value));
            return ` ${labels.length > 0 ? labels.join(', ') : '…'}`;
        }

        if (field?.type === 'boolean') {
            return ` ${node.value ? 'yes' : 'no'}`;
        }

        return ` ${this.labelForValue(field, node.value) || '…'}${field?.unit ? ` ${field.unit}` : ''}`;
    }

    labelForValue(field, value) {
        const option = field?.options?.find((item) => optionValue(item) === String(value));
        return option ? optionLabel(option) : normalizedString(value);
    }

    countNodes() {
        const count = (group) => group.rules.reduce(
            (total, node) => total + 1 + (node.group ? count(node) : 0),
            0,
        );

        return count(this.definition.criteria) + count(this.definition.exceptions);
    }

    validateDefinition(requireCriteria = this.enabledInput.checked) {
        const errors = new Map();
        const ids = new Set();
        let nodes = 0;

        const addError = (id, message) => {
            const key = id || '__definition';
            errors.set(key, [...(errors.get(key) || []), message]);
        };

        const validateGroup = (group, depth, treeName, isRoot = false) => {
            if (!['all', 'any'].includes(group.group)) {
                addError(group.id, 'Choose whether all or any conditions in this group must match.');
            }

            if (depth > MAX_DEPTH) {
                addError(group.id, `Groups can be nested up to ${MAX_DEPTH} levels.`);
            }

            if (group.rules.length > MAX_CHILDREN) {
                addError(group.id, `This group can contain at most ${MAX_CHILDREN} items.`);
            }

            if (!isRoot && group.rules.length === 0) {
                addError(group.id, 'Add a condition to this group or remove the empty group.');
            }

            group.rules.forEach((node) => {
                nodes += 1;

                if (!node.id) {
                    addError('__definition', 'Every condition must have an identifier.');
                } else if (ids.has(node.id)) {
                    addError(node.id, 'This condition has a duplicate identifier. Remove it and add it again.');
                } else {
                    ids.add(node.id);
                }

                if (node.group) {
                    validateGroup(node, depth + 1, treeName);
                } else {
                    this.validateCondition(node, addError);
                }
            });

            if (treeName === 'criteria' && isRoot && requireCriteria && group.rules.length === 0) {
                addError('__criteria', 'Add at least one alert condition before enabling this rule.');
            }
        };

        if (this.definition.schema_version !== SCHEMA_VERSION) {
            addError('__definition', `This editor supports schema version ${SCHEMA_VERSION}.`);
        }

        validateGroup(this.definition.criteria, 1, 'criteria', true);
        validateGroup(this.definition.exceptions, 1, 'exceptions', true);

        if (nodes > MAX_NODES) {
            addError('__definition', `A rule can contain at most ${MAX_NODES} conditions and groups.`);
        }

        return errors;
    }

    validateCondition(node, addError) {
        const field = this.fieldMap.get(node.field);

        if (!field || !this.isFieldCompatible(field)) {
            addError(node.id, 'Choose a field that is available for the selected target.');
            return;
        }

        if (!field.operators.includes(node.operator)) {
            addError(node.id, `Choose a valid condition for ${field.label}.`);
            return;
        }

        if (VALUELESS_OPERATORS.has(node.operator)) {
            return;
        }

        if (RANGE_OPERATORS.has(node.operator)) {
            const minimum = Number(node.value?.min);
            const maximum = Number(node.value?.max);

            if (node.value?.min === '' || node.value?.max === '' || !Number.isFinite(minimum) || !Number.isFinite(maximum)) {
                addError(node.id, `Enter both a valid minimum and maximum for ${field.label}.`);
            } else if (minimum > maximum) {
                addError(node.id, `The minimum for ${field.label} cannot be greater than the maximum.`);
            }

            return;
        }

        if (DURATION_OPERATORS.has(node.operator) || field.type === 'duration') {
            const amount = Number(node.value?.amount);

            if (!Number.isFinite(amount) || amount <= 0) {
                addError(node.id, `Enter a duration greater than zero for ${field.label}.`);
            }

            return;
        }

        if (MULTI_OPERATORS.has(node.operator) || ['multi', 'project'].includes(field.type)) {
            if (!Array.isArray(node.value) || node.value.length === 0) {
                addError(node.id, `Choose at least one value for ${field.label}.`);
            } else if (node.value.length > MAX_MULTI_VALUES) {
                addError(node.id, `Choose no more than ${MAX_MULTI_VALUES} values for ${field.label}.`);
            }

            return;
        }

        if (field.type === 'number' || field.type === 'range') {
            if (normalizedString(node.value) === '' || !Number.isFinite(Number(node.value))) {
                addError(node.id, `Enter a valid number for ${field.label}.`);
            }

            return;
        }

        if (field.type === 'datetime') {
            if (normalizedString(node.value) === '' || Number.isNaN(Date.parse(node.value))) {
                addError(node.id, `Choose a valid date for ${field.label}.`);
            }

            return;
        }

        if (field.type !== 'boolean' && normalizedString(node.value) === '') {
            addError(node.id, `Enter a value for ${field.label}.`);
        }
    }

    validateAndRenderErrors(requireCriteria) {
        this.errors = this.validateDefinition(requireCriteria);
        this.root.querySelectorAll('[data-audit-inline-errors]').forEach((target) => {
            const messages = this.errors.get(target.dataset.auditInlineErrors) || [];
            target.replaceChildren();
            target.hidden = messages.length === 0;
            messages.forEach((message) => {
                target.appendChild(createElement('p', { text: message }));
            });
        });

        const definitionErrors = [
            ...(this.errors.get('__definition') || []),
            ...(this.errors.get('__criteria') || []),
        ];
        const summary = this.root.querySelector('[data-audit-definition-errors]');

        if (summary) {
            summary.replaceChildren();
            summary.hidden = definitionErrors.length === 0;
            definitionErrors.forEach((message) => summary.appendChild(createElement('p', { text: message })));
        }

        return this.errors.size === 0;
    }

    renderInlineErrors(nodeId) {
        const target = createElement('div', {
            className: 'grid gap-1 text-sm leading-5 text-error',
            attributes: {
                'data-audit-inline-errors': nodeId,
                role: 'alert',
            },
        });
        const messages = this.errors.get(nodeId) || [];
        target.hidden = messages.length === 0;
        messages.forEach((message) => target.appendChild(createElement('p', { text: message })));
        return target;
    }

    async handleSubmit(event) {
        this.syncHiddenInput();

        if (this.allowNextSubmit) {
            this.allowNextSubmit = false;
            return;
        }

        const valid = this.validateAndRenderErrors(this.enabledInput.checked);

        if (!valid) {
            event.preventDefault();
            this.focusFirstError();
            return;
        }

        if (!this.requiresImpactConfirmation()) {
            this.clearConfirmation();
            return;
        }

        event.preventDefault();
        this.pendingSubmitter = event.submitter;
        const preview = await this.runPreview({ forActivation: true });

        if (preview) {
            this.openImpactDialog(preview);
        }
    }

    requiresImpactConfirmation() {
        if (!this.enabledInput.checked) {
            return false;
        }

        const current = this.behaviorSignature(this.definition, this.targetInput.value);
        return !this.originalEnabled || current !== this.originalBehavior;
    }

    behaviorSignature(definition, target) {
        const canonicalNode = (node) => {
            if (node.group) {
                const rules = node.rules.map(canonicalNode).sort((left, right) => (
                    JSON.stringify(left).localeCompare(JSON.stringify(right))
                ));
                return { group: node.group, rules };
            }

            return {
                field: node.field,
                operator: node.operator,
                value: clone(node.value),
            };
        };
        const canonicalGroup = (group) => ({
            group: group.group,
            rules: group.rules.map(canonicalNode).sort((left, right) => (
                JSON.stringify(left).localeCompare(JSON.stringify(right))
            )),
        });

        return JSON.stringify({
            target,
            schema_version: SCHEMA_VERSION,
            criteria: canonicalGroup(definition.criteria),
            exceptions: canonicalGroup(definition.exceptions),
        });
    }

    async runPreview({ forActivation }) {
        if (!this.previewUrl) {
            this.setPreviewStatus('The preview endpoint is not configured.', 'error');
            return null;
        }

        if (!this.validateAndRenderErrors(true)) {
            this.setPreviewStatus('Correct the highlighted conditions before testing this rule.', 'error');
            this.focusFirstError();
            return null;
        }

        this.previewController?.abort();
        this.previewController = new AbortController();
        this.setPreviewLoading(true, forActivation ? 'Checking activation impact…' : 'Testing rule…');

        try {
            const csrfToken = this.form?.querySelector('input[name="_token"]')?.value || this.csrfToken;
            const response = await fetch(this.previewUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                },
                body: JSON.stringify({
                    ...(csrfToken ? { _token: csrfToken } : {}),
                    target_type: this.targetInput.value,
                    definition: this.definition,
                }),
                signal: this.previewController.signal,
            });
            const body = await response.json().catch(() => ({}));

            if (!response.ok) {
                const message = this.previewErrorMessage(body, response.status);
                this.setPreviewStatus(message, 'error');
                this.renderServerErrors(body.errors || body.data?.errors || {});
                return null;
            }

            const payload = body.data && typeof body.data === 'object' ? body.data : body;
            const preview = {
                matchCount: Number(payload.match_count ?? payload.count ?? 0),
                samples: Array.isArray(payload.samples) ? payload.samples.slice(0, 20) : [],
                warnings: Array.isArray(payload.warnings) ? payload.warnings : [],
                duration: payload.evaluation_time_ms ?? payload.duration_ms ?? payload.duration ?? null,
                fingerprint: payload.definition_fingerprint ?? payload.fingerprint ?? '',
                confirmationToken: payload.confirmation_token ?? payload.token ?? '',
                summary: payload.plain_language_summary ?? payload.normalized_definition_summary ?? payload.summary ?? '',
            };
            this.previewState = preview;
            this.renderPreview(preview);
            this.setPreviewStatus(
                preview.warnings.length > 0 ? 'Preview completed with warnings.' : 'Preview completed.',
                preview.warnings.length > 0 ? 'warning' : 'success',
            );
            return preview;
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.setPreviewStatus('The impact check failed. Nothing was saved or enabled.', 'error');
            }

            return null;
        } finally {
            this.setPreviewLoading(false);
        }
    }

    previewErrorMessage(body, status) {
        if (status === 419) {
            return 'Your session expired. Reload this page before testing or saving the rule.';
        }

        if (typeof body.message === 'string' && body.message.trim() !== '') {
            return body.message;
        }

        if (status === 422) {
            return 'The rule could not be tested. Correct the highlighted conditions and try again.';
        }

        return 'The impact check failed. Nothing was saved or enabled.';
    }

    renderServerErrors(errors) {
        const messages = Object.values(errors).flat().filter((message) => typeof message === 'string');
        const summary = this.root.querySelector('[data-audit-definition-errors]');

        if (!summary || messages.length === 0) {
            return;
        }

        summary.replaceChildren();
        summary.hidden = false;
        messages.forEach((message) => summary.appendChild(createElement('p', { text: message })));
        summary.focus?.();
    }

    renderPreview(preview) {
        if (this.matchCountTarget) {
            this.matchCountTarget.textContent = new Intl.NumberFormat().format(preview.matchCount);
        }

        this.renderSamples(this.samplesTarget, preview.samples);
        this.renderWarnings(this.warningsTarget, preview.warnings);

        if (this.previewDurationTarget) {
            this.previewDurationTarget.textContent = preview.duration === null
                ? ''
                : `${new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 }).format(preview.duration)} ms`;
        }
    }

    renderSamples(target, samples) {
        if (!target) {
            return;
        }

        target.replaceChildren();
        target.hidden = false;

        if (samples.length === 0) {
            target.appendChild(createElement('p', {
                className: 'text-sm text-base-content/60',
                text: 'No sample targets matched.',
            }));
            return;
        }

        const list = createElement('ul', { className: 'grid gap-2', attributes: { role: 'list' } });
        samples.forEach((sample) => {
            const item = createElement('li', { className: 'text-sm leading-5' });
            item.textContent = this.sampleLabel(sample);
            list.appendChild(item);
        });
        target.appendChild(list);
    }

    sampleLabel(sample) {
        if (typeof sample === 'string' || typeof sample === 'number') {
            return String(sample);
        }

        return String(sample?.label || sample?.name || sample?.target_name || sample?.target || 'Matching target');
    }

    renderWarnings(target, warnings) {
        if (!target) {
            return;
        }

        target.replaceChildren();
        target.hidden = warnings.length === 0;
        warnings.forEach((warning) => {
            target.appendChild(createElement('p', {
                text: typeof warning === 'string' ? warning : warning?.message || 'Preview warning',
            }));
        });
    }

    setPreviewLoading(loading, message = '') {
        if (this.testButton) {
            this.testButton.disabled = loading;
            this.testButton.classList.toggle('loading', loading);
        }

        this.root.querySelectorAll('[data-audit-submit]').forEach((button) => {
            button.disabled = loading;
        });

        if (loading) {
            this.setPreviewStatus(message, 'loading');
        }
    }

    setPreviewStatus(message, tone = 'neutral') {
        if (!this.previewStatusTarget) {
            return;
        }

        this.previewStatusTarget.textContent = message;
        this.previewStatusTarget.dataset.tone = tone;
        this.previewStatusTarget.setAttribute('role', tone === 'error' ? 'alert' : 'status');
    }

    openImpactDialog(preview) {
        if (!(this.impactDialog instanceof HTMLDialogElement)) {
            this.setPreviewStatus('The activation confirmation dialog is missing.', 'error');
            return;
        }

        const count = this.impactDialog.querySelector('[data-audit-impact-count]');
        if (count) {
            count.textContent = new Intl.NumberFormat().format(preview.matchCount);
        }

        this.renderSamples(this.impactDialog.querySelector('[data-audit-impact-samples]'), preview.samples);
        this.renderWarnings(this.impactDialog.querySelector('[data-audit-impact-warnings]'), preview.warnings);
        const resetNotice = this.impactDialog.querySelector('[data-audit-impact-reset-notice]');

        if (resetNotice) {
            resetNotice.textContent = this.originalEnabled
                ? 'This changes rule behavior. Existing findings will close and matching targets will open fresh findings under the new revision.'
                : 'This activates the rule and evaluates matching targets as a fresh revision.';
        }

        const confirm = this.impactDialog.querySelector('[data-audit-impact-confirm]');
        if (confirm) {
            confirm.disabled = !preview.confirmationToken;
        }

        this.impactDialog.showModal();
    }

    clearConfirmation() {
        this.confirmationInput.value = '';
        this.previewState = null;
    }

    focusFirstError() {
        const firstNodeId = [...this.errors.keys()].find((key) => !key.startsWith('__'));

        if (firstNodeId) {
            this.focusNode(firstNodeId);
            return;
        }

        this.criteriaMount.querySelector('button, select, input')?.focus();
    }

    announce(message) {
        let liveRegion = this.root.querySelector('[data-audit-live-region]');

        if (!liveRegion) {
            liveRegion = createElement('div', {
                className: 'sr-only',
                attributes: {
                    'data-audit-live-region': '',
                    'aria-live': 'polite',
                    'aria-atomic': 'true',
                },
            });
            this.root.appendChild(liveRegion);
        }

        liveRegion.textContent = '';
        window.requestAnimationFrame(() => {
            liveRegion.textContent = message;
        });
    }
}

export const initAuditRuleBuilders = (root = document) => {
    const builderRoots = [];

    if (root instanceof Element && root.matches('[data-audit-rule-builder]')) {
        builderRoots.push(root);
    }

    builderRoots.push(...root.querySelectorAll('[data-audit-rule-builder]'));

    builderRoots.forEach((builderRoot) => {
        if (builderRoot.auditRuleBuilder) {
            return;
        }

        try {
            builderRoot.auditRuleBuilder = new AuditRuleBuilder(builderRoot);
        } catch (error) {
            console.error('Unable to initialize the audit rule builder.', error);
        }
    });
};
