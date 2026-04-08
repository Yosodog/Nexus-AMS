import './bootstrap';

const enableMobileTableScrolling = () => {
    if (window.innerWidth >= 768) {
        return;
    }

    document.querySelectorAll('main table').forEach((table) => {
        if (table.closest('.overflow-x-auto, .table-responsive')) {
            return;
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'overflow-x-auto';
        table.parentNode.insertBefore(wrapper, table);
        wrapper.appendChild(table);
    });
};

const enableBootstrapTooltipCompatibility = () => {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
        const title = element.getAttribute('title');
        if (!title) {
            return;
        }

        element.classList.add('tooltip');
        element.setAttribute('data-tip', title);
        element.removeAttribute('title');
    });
};

const enableBootstrapModalCompatibility = () => {
    document.querySelectorAll('[data-bs-toggle="modal"][data-bs-target]').forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
            event.preventDefault();

            const target = document.querySelector(trigger.getAttribute('data-bs-target'));
            if (!target) {
                return;
            }

            if (typeof target.showModal === 'function') {
                target.showModal();
                target.dispatchEvent(new Event('show.bs.modal'));
                return;
            }

            target.classList.add('show');
            target.style.display = 'block';
            document.body.classList.add('modal-open');
            target.dispatchEvent(new Event('show.bs.modal'));
        });
    });

    document.querySelectorAll('[data-bs-dismiss="modal"]').forEach((closeTrigger) => {
        closeTrigger.addEventListener('click', (event) => {
            event.preventDefault();

            const modal = closeTrigger.closest('.modal');
            if (!modal) {
                return;
            }

            if (typeof modal.close === 'function') {
                modal.close();
                modal.dispatchEvent(new Event('hidden.bs.modal'));
                return;
            }

            modal.classList.remove('show');
            modal.style.display = 'none';
            document.body.classList.remove('modal-open');
            modal.dispatchEvent(new Event('hidden.bs.modal'));
        });
    });
};

const enableBootstrapCollapseCompatibility = () => {
    document.querySelectorAll('[data-bs-toggle="collapse"][data-bs-target]').forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
            event.preventDefault();

            const target = document.querySelector(trigger.getAttribute('data-bs-target'));
            if (!target) {
                return;
            }

            target.classList.toggle('show');
        });
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        enableMobileTableScrolling();
        enableBootstrapTooltipCompatibility();
        enableBootstrapModalCompatibility();
        enableBootstrapCollapseCompatibility();
    });
} else {
    enableMobileTableScrolling();
    enableBootstrapTooltipCompatibility();
    enableBootstrapModalCompatibility();
    enableBootstrapCollapseCompatibility();
}
