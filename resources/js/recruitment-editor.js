import { ClassicEditor, Essentials, Bold, Italic, Font, Paragraph } from 'ckeditor5';

import 'ckeditor5/ckeditor5.css';

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.js-ckeditor').forEach((element) => {
        ClassicEditor.create(element, {
            licenseKey: 'GPL',
            plugins: [Essentials, Bold, Italic, Font, Paragraph],
            toolbar: [
                'undo', 'redo', '|', 'bold', 'italic', '|',
                'fontSize', 'fontFamily', 'fontColor', 'fontBackgroundColor',
            ],
        }).catch((error) => {
            console.error('Failed to initialize CKEditor 5 for recruitment messaging.', error);
        });
    });
});
