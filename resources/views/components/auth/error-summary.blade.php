@props([
    'errorBag' => 'default',
    'fieldIds' => [],
    'focus' => true,
    'id' => null,
    'only' => null,
    'title' => 'Check the form and try again.',
])

<x-form.error-summary
    :id="$id"
    :title="$title"
    :error-bag="$errorBag"
    :field-ids="$fieldIds"
    :focus="$focus"
    :only="$only"
/>
