import { Jodit } from 'jodit';

import 'jodit/es2021/jodit.min.css';
import 'jodit/esm/plugins/clean-html/clean-html.js';
import 'jodit/esm/plugins/fullsize/fullsize.js';
import 'jodit/esm/plugins/hr/hr.js';
import 'jodit/esm/plugins/indent/indent.js';
import 'jodit/esm/plugins/justify/justify.js';
import 'jodit/esm/plugins/search/search.js';
import 'jodit/esm/plugins/source/config.js';
import 'jodit/esm/plugins/symbols/symbols.js';
import 'jodit/esm/plugins/video/config.js';

const toolbarButtons = [
    'undo', 'redo', '|',
    'find', '|',
    'paragraph', '|',
    'font', 'fontsize', 'brush', '|',
    'bold', 'italic', 'underline', 'strikethrough', 'inlineCode', 'eraser', '|',
    'subscript', 'superscript', '|',
    'align', '|',
    'ul', 'ol', 'taskList', '|',
    'outdent', 'indent', '|',
    'link', 'blockQuote', 'codeBlock', '|',
    'table', 'hr', 'pageBreak', '|',
    'image', 'imageCaption', 'mediaEmbed', 'symbols', '|',
    'fullsize',
];

function normalizeYouTubeEmbedUrl(rawUrl) {
    try {
        const url = new URL(rawUrl);
        const host = url.hostname.toLowerCase().replace(/^www\./, '');
        let videoId = '';

        if (host === 'youtu.be') {
            videoId = url.pathname.split('/').filter(Boolean)[0] ?? '';
        } else if (host === 'youtube.com' || host.endsWith('.youtube.com')) {
            if (url.pathname === '/watch') {
                videoId = url.searchParams.get('v') ?? '';
            } else {
                const [route, id] = url.pathname.split('/').filter(Boolean);
                if (['embed', 'shorts'].includes(route)) {
                    videoId = id ?? '';
                }
            }
        }

        return /^[A-Za-z0-9_-]{6,}$/.test(videoId)
            ? `https://www.youtube.com/embed/${videoId}`
            : null;
    } catch {
        return null;
    }
}

const customControls = {
    inlineCode: {
        icon: 'source',
        tooltip: 'Inline code',
        exec(editor) {
            editor.s.commitStyle({ element: 'code' });
            editor.synchronizeValues();
        },
    },
    blockQuote: {
        icon: 'paragraph',
        tooltip: 'Block quote',
        exec(editor) {
            editor.s.commitStyle({ element: 'blockquote' });
            editor.synchronizeValues();
        },
    },
    codeBlock: {
        icon: 'source',
        tooltip: 'Code block',
        exec(editor) {
            editor.s.commitStyle({ element: 'pre' });
            editor.synchronizeValues();
        },
    },
    mediaEmbed: {
        icon: 'video',
        tooltip: 'Insert YouTube video',
        popup(editor, current, close) {
            const form = editor.od.createElement('form');
            const field = editor.od.createElement('div');
            const input = editor.od.createElement('input');
            const button = editor.od.createElement('button');

            form.className = 'jodit-form';
            field.className = 'jodit-ui-block';
            input.className = 'jodit-input';
            input.type = 'url';
            input.placeholder = 'https://www.youtube.com/watch?v=…';
            input.required = true;
            input.setAttribute('aria-label', 'YouTube URL');
            button.className = 'jodit-ui-button jodit-ui-button_variant_primary';
            button.type = 'submit';
            button.textContent = 'Insert';
            field.append(input);
            form.append(field, button);
            editor.s.save();

            form.addEventListener('submit', (event) => {
                event.preventDefault();

                const embedUrl = normalizeYouTubeEmbedUrl(input.value);
                if (!embedUrl) {
                    editor.message.error('Enter a valid YouTube URL.');

                    return;
                }

                editor.s.restore();
                editor.s.insertHTML(`<iframe src="${embedUrl}" title="YouTube video" width="560" height="315" allowfullscreen></iframe>`);
                close();
            });

            queueMicrotask(() => input.focus());

            return form;
        },
    },
    imageCaption: {
        icon: 'image',
        tooltip: 'Toggle image caption',
        exec(editor) {
            const current = editor.s.current();
            const currentElement = current instanceof Element ? current : current?.parentElement;
            const image = currentElement?.matches('img')
                ? currentElement
                : currentElement?.querySelector('img');

            if (!(image instanceof HTMLImageElement) || !editor.editor.contains(image)) {
                editor.message.info('Select an image to add or remove its caption.');

                return;
            }

            const existingFigure = image.closest('figure');
            const existingCaption = existingFigure?.querySelector(':scope > figcaption');

            if (existingFigure && existingCaption && editor.editor.contains(existingFigure)) {
                existingFigure.replaceWith(image);
                editor.s.setCursorAfter(image);
                editor.synchronizeValues();

                return;
            }

            const figure = editor.createInside.element('figure');
            const caption = editor.createInside.element('figcaption');

            figure.className = 'image';
            caption.innerHTML = '<br>';
            image.replaceWith(figure);
            figure.append(image, caption);
            editor.s.setCursorIn(caption);
            editor.synchronizeValues();
        },
    },
    taskList: {
        icon: 'ul',
        tooltip: 'Task list',
        exec(editor) {
            const current = editor.s.current();
            const currentElement = current instanceof Element ? current : current?.parentElement;
            const existingTaskList = currentElement?.closest('ul.todo-list');

            if (existingTaskList && editor.editor.contains(existingTaskList)) {
                existingTaskList.classList.remove('todo-list');
                existingTaskList.querySelectorAll(':scope > li').forEach((item) => {
                    const label = item.querySelector(':scope > label.todo-list__label');
                    const description = label?.querySelector(':scope > .todo-list__label__description');

                    if (!label || !description) {
                        return;
                    }

                    while (description.firstChild) {
                        item.insertBefore(description.firstChild, label);
                    }

                    label.remove();
                });
                editor.synchronizeValues();

                return;
            }

            editor.execCommand('insertUnorderedList');

            const list = (editor.s.current() instanceof Element
                ? editor.s.current()
                : editor.s.current()?.parentElement)?.closest('ul');

            if (!list || !editor.editor.contains(list)) {
                return;
            }

            list.classList.add('todo-list');
            list.querySelectorAll(':scope > li').forEach((item) => {
                if (item.querySelector(':scope > label.todo-list__label')) {
                    return;
                }

                const label = editor.createInside.element('label');
                const checkbox = editor.createInside.element('input');
                const description = editor.createInside.element('span');

                label.className = 'todo-list__label';
                checkbox.type = 'checkbox';
                description.className = 'todo-list__label__description';

                while (item.firstChild) {
                    description.append(item.firstChild);
                }

                label.append(checkbox, description);
                item.append(label);
            });

            editor.synchronizeValues();
        },
    },
    pageBreak: {
        icon: 'hr',
        tooltip: 'Page break',
        exec(editor) {
            editor.s.insertHTML('<div class="page-break" style="page-break-after: always;"><span style="display: none;">&nbsp;</span></div><p><br></p>');
        },
    },
};

const editors = new Map();
window.__joditInstances = editors;

function buildUploaderOptions(element) {
    const uploadUrl = element.dataset.editorUploadUrl;

    if (!uploadUrl) {
        return {
            insertImageAsBase64URI: false,
            showTabInFileSelector: false,
        };
    }

    const csrfToken = element.dataset.editorCsrf
        || document.querySelector('meta[name="csrf-token"]')?.content
        || '';

    return {
        url: uploadUrl,
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken,
        },
        filesVariableName: () => 'image',
        imagesExtensions: ['jpg', 'jpeg', 'png', 'webp'],
        beforeUpload(files) {
            if (files.length === 1) {
                return true;
            }

            this.j.message.error('Upload one image at a time.');

            return false;
        },
        isSuccess: (response) => Boolean(response?.success && response?.file?.url),
        getMessage: (response) => response?.message
            || response?.errors?.image?.join(' ')
            || 'The image could not be uploaded.',
        process: (response) => ({
            files: [response.file.url],
            path: '',
            baseurl: '',
            isImages: [true],
        }),
    };
}

function trackEditor(element, editor) {
    editors.set(element, editor);
    element.dataset.joditReady = 'true';
    element.dispatchEvent(new CustomEvent('richtext:ready', {
        detail: { editor },
    }));
}

function destroyDetachedEditors() {
    editors.forEach((editor, element) => {
        if (document.contains(element)) {
            return;
        }

        editor.destruct();
        editors.delete(element);
    });
}

function getEditorHeight(element) {
    const configuredHeight = Number.parseInt(element.dataset.editorHeight ?? '', 10);

    return Number.isNaN(configuredHeight) ? 420 : Math.max(configuredHeight, 200);
}

const initJoditEditors = () => {
    destroyDetachedEditors();

    document.querySelectorAll('.js-jodit').forEach((element) => {
        if (!(element instanceof HTMLTextAreaElement)
            || editors.has(element)
            || element.dataset.joditReady === 'true') {
            return;
        }

        try {
            const editorHeight = getEditorHeight(element);
            const editor = Jodit.make(element, {
                buttons: toolbarButtons,
                controls: customControls,
                height: editorHeight,
                minHeight: editorHeight,
                toolbarAdaptive: true,
                toolbarSticky: false,
                useSearch: true,
                showCharsCounter: true,
                showWordsCounter: true,
                showXPathInStatusbar: false,
                imageDefaultWidth: 600,
                image: {
                    editAlt: true,
                    editTitle: true,
                    editSize: true,
                    editAlign: true,
                    showPreview: true,
                },
                uploader: buildUploaderOptions(element),
            });

            trackEditor(element, editor);
        } catch (error) {
            console.error('Failed to initialize Jodit', error);
        }
    });
};

document.addEventListener('submit', (event) => {
    if (!(event.target instanceof HTMLFormElement)) {
        return;
    }

    editors.forEach((editor, element) => {
        if (element.form === event.target) {
            editor.synchronizeValues();
        }
    });
}, true);

document.addEventListener('DOMContentLoaded', initJoditEditors);
document.addEventListener('livewire:navigated', initJoditEditors);

window.getRichTextEditorInstance = function getRichTextEditorInstance(element) {
    return editors.get(element) ?? null;
};
