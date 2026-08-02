const cloneData = (data) => JSON.parse(JSON.stringify(data));

let builderInstanceCount = 0;

class GrantRequirementBuilder {
    constructor(root) {
        this.root = root;
        this.config = this.readConfig();
        this.fields = new Map((this.config.fields || []).map((field) => [field.key, field]));
        this.operators = new Map((this.config.operators || []).map((operator) => [operator.value, operator]));
        this.defaultTree = this.ensureValidTree(this.config.default_tree, { group: 'all', rules: [] });
        this.tree = cloneData(this.defaultTree);
        this.instanceId = `grant-requirement-builder-${builderInstanceCount += 1}`;
        this.renderVersion = 0;

        this.hiddenInput = root.querySelector('[data-grant-requirement-input]');
        this.rulesTarget = root.querySelector('[data-grant-requirement-rules]');
        this.rootGroupMode = root.querySelector('[data-grant-requirement-root-mode]');
        this.ruleCountTarget = root.querySelector('[data-grant-requirement-rule-count]');
        this.summaryTarget = root.querySelector('[data-grant-requirement-summary-hint]');
        this.rootRuleBadge = root.querySelector('[data-grant-requirement-root-badge]');

        if (!(this.hiddenInput instanceof HTMLInputElement) || !this.rulesTarget || !(this.rootGroupMode instanceof HTMLSelectElement)) {
            throw new Error('Grant requirement builder markup is incomplete.');
        }

        this.bindActions();
        this.renderRootGroupModeSelect();
        this.setValue(this.readInitialValue());
    }

    readConfig() {
        const configElement = this.root.querySelector('[data-grant-requirement-config]');

        if (!configElement) {
            throw new Error('Grant requirement builder configuration is missing.');
        }

        return JSON.parse(configElement.textContent || '{}');
    }

    readInitialValue() {
        const pendingValue = this.root.dataset.grantRequirementInitialValue;

        if (pendingValue !== undefined) {
            delete this.root.dataset.grantRequirementInitialValue;
            return pendingValue;
        }

        return this.hiddenInput.value || this.defaultTree;
    }

    bindActions() {
        this.root.querySelector('[data-grant-requirement-action="add-condition"]')?.addEventListener('click', () => {
            this.tree.rules.push(this.createEmptyCondition());
            this.render();
        });

        this.root.querySelector('[data-grant-requirement-action="add-group"]')?.addEventListener('click', () => {
            this.tree.rules.push(this.createEmptyGroup());
            this.render();
        });

        this.rootGroupMode.addEventListener('change', (event) => {
            this.tree.group = event.target.value;
            this.syncHiddenInput();
            this.updateSummary();
        });
    }

    setValue(value) {
        let parsedValue = value;

        if (typeof value === 'string') {
            try {
                parsedValue = value.trim() === '' ? this.defaultTree : JSON.parse(value);
            } catch {
                parsedValue = this.defaultTree;
            }
        }

        this.tree = this.ensureValidTree(parsedValue || this.defaultTree);
        this.renderRootGroupModeSelect();
        this.render();
    }

    reset() {
        this.setValue(this.defaultTree);
    }

    getValue() {
        return cloneData(this.ensureValidTree(this.tree));
    }

    createEmptyCondition(fieldKey = null) {
        const initialField = fieldKey || this.config.fields?.[0]?.key || 'num_cities';
        const field = this.fields.get(initialField);
        const operator = field?.operators?.[0] || 'gte';

        return {
            field: initialField,
            operator,
            value: this.defaultValueFor(field, operator),
            message: '',
        };
    }

    createEmptyGroup(group = 'all') {
        return { group, rules: [] };
    }

    defaultValueFor(field, operator) {
        if (!field) {
            return '';
        }

        if (field.type === 'number') {
            return ['between', 'not_between'].includes(operator) ? { min: '', max: '' } : '';
        }

        if (field.type === 'enum') {
            return ['in', 'not_in'].includes(operator) ? [] : field.options?.[0]?.value || '';
        }

        return [];
    }

    ensureValidTree(tree, fallback = this.defaultTree || { group: 'all', rules: [] }) {
        if (!tree || typeof tree !== 'object' || Array.isArray(tree)) {
            return cloneData(fallback);
        }

        const group = ['all', 'any', 'not'].includes(tree.group) ? tree.group : 'all';
        const rules = Array.isArray(tree.rules)
            ? tree.rules.map((node) => this.ensureValidNode(node)).filter(Boolean)
            : [];

        return { group, rules };
    }

    ensureValidNode(node) {
        if (!node || typeof node !== 'object' || Array.isArray(node)) {
            return null;
        }

        if (Object.prototype.hasOwnProperty.call(node, 'group')) {
            return this.ensureValidTree(node, { group: 'all', rules: [] });
        }

        const fallbackField = this.config.fields?.[0];
        const field = this.fields.get(node.field) || fallbackField;

        if (!field) {
            return null;
        }

        const operator = field.operators.includes(node.operator) ? node.operator : field.operators[0];

        return {
            field: field.key,
            operator,
            value: this.normalizeConditionValue(field, operator, node.value),
            message: typeof node.message === 'string' ? node.message : '',
        };
    }

    normalizeConditionValue(field, operator, value) {
        if (field.type === 'number') {
            if (['between', 'not_between'].includes(operator)) {
                return {
                    min: value && typeof value === 'object' ? value.min ?? '' : '',
                    max: value && typeof value === 'object' ? value.max ?? '' : '',
                };
            }

            return value ?? '';
        }

        if (field.type === 'enum') {
            if (['in', 'not_in'].includes(operator)) {
                return Array.isArray(value) ? value : [];
            }

            return value ?? field.options?.[0]?.value ?? '';
        }

        return Array.isArray(value) ? value : [];
    }

    renderRootGroupModeSelect() {
        this.rootGroupMode.replaceChildren();

        (this.config.groups || []).forEach((groupOption) => {
            const option = document.createElement('option');
            option.value = groupOption.value;
            option.textContent = groupOption.label;
            option.selected = groupOption.value === this.tree.group;
            this.rootGroupMode.appendChild(option);
        });
    }

    render() {
        this.renderVersion += 1;
        this.rulesTarget.replaceChildren();

        this.tree.rules.forEach((rule, index) => {
            this.rulesTarget.appendChild(this.renderNode(rule, [index], true));
        });

        this.rootGroupMode.value = this.tree.group;
        this.syncHiddenInput();
        this.updateSummary();
    }

    renderNode(node, path, isRootLevel = false) {
        return node.group
            ? this.renderGroupNode(node, path, isRootLevel)
            : this.renderConditionNode(node, path);
    }

    renderGroupNode(node, path, isRootLevel) {
        const card = document.createElement('div');
        card.className = 'rounded-box border border-base-300 bg-base-200 p-3';

        const header = document.createElement('div');
        header.className = 'mb-3 flex flex-wrap items-center justify-between gap-2';
        card.appendChild(header);

        const labelWrap = document.createElement('div');
        header.appendChild(labelWrap);

        const label = document.createElement('div');
        label.className = 'text-sm font-semibold';
        label.textContent = isRootLevel ? 'Nested group' : 'Requirement group';
        labelWrap.appendChild(label);

        const help = document.createElement('div');
        help.className = 'text-xs text-base-content/50';
        help.textContent = 'Use nested logic when one path or another should qualify.';
        labelWrap.appendChild(help);

        const actions = document.createElement('div');
        actions.className = 'flex flex-wrap gap-2';
        header.appendChild(actions);
        actions.appendChild(this.createButton('Condition', 'btn btn-outline btn-primary btn-xs', () => {
            node.rules.push(this.createEmptyCondition());
            this.render();
        }));
        actions.appendChild(this.createButton('Group', 'btn btn-outline btn-xs', () => {
            node.rules.push(this.createEmptyGroup());
            this.render();
        }));
        actions.appendChild(this.createMoveButton('Move up', path, -1));
        actions.appendChild(this.createMoveButton('Move down', path, 1));
        actions.appendChild(this.createButton('Remove', 'btn btn-error btn-outline btn-xs', () => {
            this.removeNodeAtPath(path);
            this.render();
        }));

        const groupSelect = document.createElement('select');
        groupSelect.className = 'select select-sm mb-3 w-full';
        groupSelect.id = this.controlId(path, 'group');
        groupSelect.setAttribute('aria-label', 'Requirement group logic');

        (this.config.groups || []).forEach((groupOption) => {
            const option = document.createElement('option');
            option.value = groupOption.value;
            option.textContent = groupOption.label;
            option.selected = groupOption.value === node.group;
            groupSelect.appendChild(option);
        });

        groupSelect.addEventListener('change', (event) => {
            node.group = event.target.value;
            this.syncHiddenInput();
            this.updateSummary();
        });
        card.appendChild(groupSelect);

        if (node.rules.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'rounded-box border border-base-300 p-3 text-sm text-base-content/50';
            empty.textContent = 'This group is empty. Add a condition or subgroup to make it active.';
            card.appendChild(empty);
        } else {
            const children = document.createElement('div');
            children.className = 'space-y-3';
            node.rules.forEach((child, childIndex) => {
                children.appendChild(this.renderNode(child, [...path, childIndex]));
            });
            card.appendChild(children);
        }

        return card;
    }

    renderConditionNode(node, path) {
        const field = this.fields.get(node.field) || this.config.fields?.[0];

        if (!field) {
            return document.createElement('div');
        }

        const allowedOperators = field.operators
            .map((operatorKey) => this.operators.get(operatorKey))
            .filter(Boolean);

        if (!field.operators.includes(node.operator)) {
            node.operator = field.operators[0];
            node.value = this.defaultValueFor(field, node.operator);
        }

        const card = document.createElement('div');
        card.className = 'rounded-box border border-base-300 bg-base-100 p-3';

        const grid = document.createElement('div');
        grid.className = 'grid grid-cols-1 gap-3 md:grid-cols-3';
        grid.appendChild(this.createFieldSelect(field, node, path));
        grid.appendChild(this.createOperatorSelect(node, allowedOperators, path));
        grid.appendChild(this.createValueEditor(field, node, path));
        card.appendChild(grid);

        const footer = document.createElement('div');
        footer.className = 'mt-3 flex flex-col items-stretch gap-3 sm:flex-row sm:items-end';
        card.appendChild(footer);

        const messageInput = document.createElement('input');
        messageInput.type = 'text';
        messageInput.className = 'input input-sm w-full';
        messageInput.placeholder = 'Optional. Shown to the applicant if this condition fails.';
        messageInput.value = node.message || '';
        messageInput.addEventListener('input', (event) => {
            node.message = event.target.value;
            this.syncHiddenInput();
        });
        footer.appendChild(this.createLabeledControl('Custom failure message', messageInput, path, 'message', 'grow'));

        const actions = document.createElement('div');
        actions.className = 'flex shrink-0 flex-wrap gap-2';
        actions.appendChild(this.createMoveButton('Move up', path, -1));
        actions.appendChild(this.createMoveButton('Move down', path, 1));
        actions.appendChild(this.createButton('Remove', 'btn btn-error btn-outline btn-xs', () => {
            this.removeNodeAtPath(path);
            this.render();
        }));
        footer.appendChild(actions);

        return card;
    }

    createFieldSelect(field, node, path) {
        const select = document.createElement('select');
        select.className = 'select select-sm w-full';
        const groupedFields = {};

        (this.config.fields || []).forEach((item) => {
            groupedFields[item.category] = groupedFields[item.category] || [];
            groupedFields[item.category].push(item);
        });

        Object.entries(groupedFields).forEach(([category, items]) => {
            const optionGroup = document.createElement('optgroup');
            optionGroup.label = category;

            items.forEach((item) => {
                const option = document.createElement('option');
                option.value = item.key;
                option.textContent = item.label;
                option.selected = item.key === field.key;
                optionGroup.appendChild(option);
            });

            select.appendChild(optionGroup);
        });

        select.addEventListener('change', (event) => {
            const nextField = this.fields.get(event.target.value);

            if (!nextField) {
                return;
            }

            node.field = nextField.key;
            node.operator = nextField.operators[0];
            node.value = this.defaultValueFor(nextField, node.operator);
            this.render();
        });

        return this.createLabeledControl('Field', select, path, 'field');
    }

    createOperatorSelect(node, allowedOperators, path) {
        const select = document.createElement('select');
        select.className = 'select select-sm w-full';

        allowedOperators.forEach((operator) => {
            const option = document.createElement('option');
            option.value = operator.value;
            option.textContent = operator.label;
            option.selected = operator.value === node.operator;
            select.appendChild(option);
        });

        select.addEventListener('change', (event) => {
            const field = this.fields.get(node.field);
            node.operator = event.target.value;
            node.value = this.defaultValueFor(field, node.operator);
            this.render();
        });

        return this.createLabeledControl('Operator', select, path, 'operator');
    }

    createValueEditor(field, node, path) {
        const wrapper = document.createElement('div');
        const label = document.createElement('div');
        label.className = 'label text-xs font-semibold';
        label.textContent = 'Value';
        wrapper.appendChild(label);

        if (field.type === 'number' && ['between', 'not_between'].includes(node.operator)) {
            const row = document.createElement('div');
            row.className = 'grid grid-cols-2 gap-2';
            row.appendChild(this.createNumberInput('Minimum', node.value?.min ?? '', path, 'minimum', (value) => {
                node.value = { ...(node.value || {}), min: value };
                this.syncHiddenInput();
                this.updateSummary();
            }));
            row.appendChild(this.createNumberInput('Maximum', node.value?.max ?? '', path, 'maximum', (value) => {
                node.value = { ...(node.value || {}), max: value };
                this.syncHiddenInput();
                this.updateSummary();
            }));
            wrapper.appendChild(row);

            return wrapper;
        }

        if (field.type === 'number') {
            const input = document.createElement('input');
            input.type = 'number';
            input.className = 'input input-sm w-full';
            input.step = 'any';
            input.value = node.value ?? '';
            input.setAttribute('aria-label', 'Requirement value');
            input.addEventListener('input', (event) => {
                node.value = event.target.value;
                this.syncHiddenInput();
                this.updateSummary();
            });
            wrapper.appendChild(input);

            return wrapper;
        }

        if (field.type === 'enum' && ['eq', 'neq'].includes(node.operator)) {
            const select = document.createElement('select');
            select.className = 'select select-sm w-full';
            select.setAttribute('aria-label', 'Requirement value');

            (field.options || []).forEach((optionData) => {
                const option = document.createElement('option');
                option.value = optionData.value;
                option.textContent = optionData.label;
                option.selected = optionData.value === node.value;
                select.appendChild(option);
            });

            select.addEventListener('change', (event) => {
                node.value = event.target.value;
                this.syncHiddenInput();
                this.updateSummary();
            });
            wrapper.appendChild(select);

            return wrapper;
        }

        const select = document.createElement('select');
        select.className = 'select select-sm w-full';
        select.multiple = true;
        select.size = Math.min(6, Math.max(4, field.options?.length || 0));
        select.setAttribute('aria-label', 'Requirement values');
        const selectedValues = Array.isArray(node.value) ? node.value : [];

        (field.options || []).forEach((optionData) => {
            const option = document.createElement('option');
            option.value = optionData.value;
            option.textContent = optionData.label;
            option.selected = selectedValues.includes(optionData.value);
            select.appendChild(option);
        });

        select.addEventListener('change', (event) => {
            node.value = Array.from(event.target.selectedOptions).map((option) => option.value);
            this.syncHiddenInput();
            this.updateSummary();
        });
        wrapper.appendChild(select);

        const help = document.createElement('div');
        help.className = 'mt-1 text-xs text-base-content/50';
        help.textContent = 'Hold Command or Ctrl to select multiple values.';
        wrapper.appendChild(help);

        return wrapper;
    }

    createNumberInput(labelText, value, path, suffix, onInput) {
        const input = document.createElement('input');
        input.type = 'number';
        input.className = 'input input-sm w-full';
        input.step = 'any';
        input.value = value;
        input.addEventListener('input', (event) => onInput(event.target.value));

        return this.createLabeledControl(labelText, input, path, suffix);
    }

    createLabeledControl(labelText, control, path, suffix, wrapperClass = '') {
        const wrapper = document.createElement('div');
        wrapper.className = wrapperClass;
        const label = document.createElement('label');
        const controlId = this.controlId(path, suffix);
        label.className = 'label text-xs font-semibold';
        label.htmlFor = controlId;
        label.textContent = labelText;
        control.id = controlId;
        wrapper.appendChild(label);
        wrapper.appendChild(control);

        return wrapper;
    }

    controlId(path, suffix) {
        return `${this.instanceId}-${this.renderVersion}-${path.join('-')}-${suffix}`;
    }

    createButton(label, classes, handler) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = classes;
        button.textContent = label;
        button.addEventListener('click', handler);

        return button;
    }

    createMoveButton(label, path, offset) {
        return this.createButton(label, 'btn btn-outline btn-xs', () => {
            this.moveNodeAtPath(path, offset);
            this.render();
        });
    }

    getParentArray(path) {
        let target = this.tree.rules;

        for (let index = 0; index < path.length - 1; index += 1) {
            target = target[path[index]].rules;
        }

        return target;
    }

    removeNodeAtPath(path) {
        this.getParentArray(path).splice(path[path.length - 1], 1);
    }

    moveNodeAtPath(path, offset) {
        const parent = this.getParentArray(path);
        const currentIndex = path[path.length - 1];
        const nextIndex = currentIndex + offset;

        if (nextIndex < 0 || nextIndex >= parent.length) {
            return;
        }

        const [item] = parent.splice(currentIndex, 1);
        parent.splice(nextIndex, 0, item);
    }

    syncHiddenInput() {
        const normalized = this.ensureValidTree(this.tree);
        this.hiddenInput.value = normalized.rules.length > 0 ? JSON.stringify(normalized) : '';
    }

    updateSummary() {
        const ruleCount = this.countNodes(this.tree.rules);

        if (this.rootRuleBadge) {
            this.rootRuleBadge.textContent = `${ruleCount} ${ruleCount === 1 ? 'rule' : 'rules'}`;
        }

        if (!this.ruleCountTarget || !this.summaryTarget) {
            return;
        }

        if (ruleCount === 0) {
            this.ruleCountTarget.textContent = this.root.dataset.emptyTitle || 'No custom requirements configured';
            this.summaryTarget.textContent = this.root.dataset.emptyHint
                || 'Applications will only enforce the standard eligibility checks until you add rules.';
            return;
        }

        this.ruleCountTarget.textContent = `${ruleCount} custom ${ruleCount === 1 ? 'rule' : 'rules'} will be enforced`;
        this.summaryTarget.textContent = this.summarizeTree(this.tree).slice(0, 3).join(' • ');
    }

    countNodes(nodes) {
        return nodes.reduce(
            (total, node) => (node.group ? total + 1 + this.countNodes(node.rules || []) : total + 1),
            0,
        );
    }

    summarizeTree(tree) {
        return (tree.rules || []).map((node) => this.summarizeNode(node));
    }

    summarizeNode(node) {
        if (node.group) {
            const labels = { all: 'All of', any: 'Any of', not: 'None of' };
            return `${labels[node.group] || 'Group'}: ${(node.rules || []).map((child) => this.summarizeNode(child)).join('; ')}`;
        }

        const field = this.fields.get(node.field);
        const operator = this.operators.get(node.operator);
        const label = field?.label || node.field;

        if (!field || !operator) {
            return label;
        }

        if (field.type === 'number') {
            if (['between', 'not_between'].includes(node.operator)) {
                return `${label} ${operator.label.toLowerCase()} ${node.value?.min ?? '?'} and ${node.value?.max ?? '?'}`;
            }

            return `${label} ${operator.label.toLowerCase()} ${node.value ?? '?'}`;
        }

        if (Array.isArray(node.value)) {
            return `${label} ${operator.label.toLowerCase()} ${node.value.join(', ')}`;
        }

        return `${label} ${operator.label.toLowerCase()} ${node.value ?? '?'}`;
    }
}

export const initGrantRequirementBuilders = (root = document) => {
    const builderRoots = [];

    if (root instanceof Element && root.matches('[data-grant-requirement-builder]')) {
        builderRoots.push(root);
    }

    builderRoots.push(...root.querySelectorAll('[data-grant-requirement-builder]'));

    builderRoots.forEach((builderRoot) => {
        if (builderRoot.grantRequirementBuilder) {
            return;
        }

        try {
            builderRoot.grantRequirementBuilder = new GrantRequirementBuilder(builderRoot);
        } catch (error) {
            console.error('Unable to initialize the grant requirement builder.', error);
        }
    });
};
