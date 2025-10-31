import {
    Alignment,
    Autoformat,
    BlockQuote,
    Bold,
    ClassicEditor,
    Code,
    CodeBlock,
    Essentials,
    FindAndReplace,
    Font,
    Heading,
    Highlight,
    HorizontalLine,
    HtmlEmbed,
    Image,
    ImageCaption,
    ImageInsert,
    ImageResize,
    ImageStyle,
    ImageToolbar,
    Indent,
    IndentBlock,
    Italic,
    Link,
    List,
    ListProperties,
    MediaEmbed,
    PageBreak,
    Paragraph,
    RemoveFormat,
    SourceEditing,
    SpecialCharacters,
    SpecialCharactersEssentials,
    Strikethrough,
    Subscript,
    Superscript,
    Table,
    TableCellProperties,
    TableColumnResize,
    TableProperties,
    TableToolbar,
    TodoList,
    Underline,
} from 'ckeditor5';

import 'ckeditor5/ckeditor5.css';

const editorPlugins = [
    Essentials,
    Paragraph,
    Heading,
    Font,
    Bold,
    Italic,
    Underline,
    Strikethrough,
    Subscript,
    Superscript,
    Code,
    BlockQuote,
    Alignment,
    Autoformat,
    Highlight,
    FindAndReplace,
    RemoveFormat,
    Link,
    List,
    ListProperties,
    TodoList,
    Indent,
    IndentBlock,
    HorizontalLine,
    CodeBlock,
    MediaEmbed,
    HtmlEmbed,
    SourceEditing,
    SpecialCharacters,
    SpecialCharactersEssentials,
    Table,
    TableToolbar,
    TableProperties,
    TableCellProperties,
    TableColumnResize,
    Image,
    ImageToolbar,
    ImageCaption,
    ImageStyle,
    ImageResize,
    ImageInsert,
    PageBreak,
];

const toolbarItems = [
    'undo', 'redo', '|',
    'findAndReplace', 'sourceEditing', '|',
    'heading', '|',
    'fontFamily', 'fontSize', 'fontColor', 'fontBackgroundColor', 'highlight', '|',
    'bold', 'italic', 'underline', 'strikethrough', 'code', 'removeFormat', '|',
    'subscript', 'superscript', '|',
    'alignment', '|',
    'bulletedList', 'numberedList', 'todoList', '|',
    'outdent', 'indent', '|',
    'link', 'blockQuote', 'codeBlock', '|',
    'insertTable', 'horizontalLine', 'pageBreak', '|',
    'insertImage', 'mediaEmbed', 'htmlEmbed', 'specialCharacters',
];

const imageConfig = {
    toolbar: [
        'toggleImageCaption',
        'imageTextAlternative',
        '|',
        'imageStyle:inline',
        'imageStyle:block',
        'imageStyle:side',
        '|',
        'resizeImage:25',
        'resizeImage:50',
        'resizeImage:75',
        'resizeImage:original',
    ],
    styles: [
        'inline',
        'block',
        'side',
    ],
    resizeOptions: [
        {
            name: 'resizeImage:25',
            value: '25',
            label: '25%',
        },
        {
            name: 'resizeImage:50',
            value: '50',
            label: '50%',
        },
        {
            name: 'resizeImage:75',
            value: '75',
            label: '75%',
        },
        {
            name: 'resizeImage:original',
            value: null,
            label: 'Original',
        },
    ],
};

const tableConfig = {
    contentToolbar: [
        'tableColumn',
        'tableRow',
        'mergeTableCells',
        'tableProperties',
        'tableCellProperties',
    ],
};

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.js-ckeditor').forEach((element) => {
        ClassicEditor.create(element, {
            licenseKey: 'GPL',
            plugins: editorPlugins,
            toolbar: {
                items: toolbarItems,
                shouldNotGroupWhenFull: true,
            },
            heading: {
                options: [
                    { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
                    { model: 'heading2', view: 'h2', title: 'Heading 2', class: 'ck-heading_heading2' },
                    { model: 'heading3', view: 'h3', title: 'Heading 3', class: 'ck-heading_heading3' },
                    { model: 'heading4', view: 'h4', title: 'Heading 4', class: 'ck-heading_heading4' },
                ],
            },
            image: imageConfig,
            table: tableConfig,
            mediaEmbed: {
                previewsInData: true,
            },
            htmlEmbed: {
                showPreviews: true,
            },
        }).catch((error) => {
            console.error('Failed to initialize CKEditor 5', error);
        });
    });
});
