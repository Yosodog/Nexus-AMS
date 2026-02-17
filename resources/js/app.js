import './bootstrap';

import {themeChange} from 'theme-change'

themeChange()

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

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enableMobileTableScrolling);
} else {
    enableMobileTableScrolling();
}
