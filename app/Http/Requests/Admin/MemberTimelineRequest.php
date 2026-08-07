<?php

namespace App\Http\Requests\Admin;

use App\Enums\MemberTimelineCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MemberTimelineRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('view-members') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'timeline_filter' => ['sometimes', 'boolean'],
            'timeline_categories' => ['sometimes', 'array', 'max:'.count(MemberTimelineCategory::cases())],
            'timeline_categories.*' => ['string', 'distinct', Rule::enum(MemberTimelineCategory::class)],
        ];
    }

    /**
     * Null means the filter has not been submitted and all permitted categories should be selected.
     *
     * @return list<MemberTimelineCategory>|null
     */
    public function timelineCategories(): ?array
    {
        if (! $this->has('timeline_filter') && ! $this->has('timeline_categories')) {
            return null;
        }

        return collect($this->validated('timeline_categories', []))
            ->map(fn (string $category): MemberTimelineCategory => MemberTimelineCategory::from($category))
            ->values()
            ->all();
    }
}
