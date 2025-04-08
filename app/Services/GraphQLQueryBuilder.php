<?php

namespace App\Services;

class GraphQLQueryBuilder
{
    public bool $includePagination = false;
    protected string $rootField;
    protected array $arguments = [];
    protected array $fields = [];
    protected bool $isMutation = false;

    /**
     * @return string
     */
    public function getRootField(): string
    {
        return $this->rootField;
    }

    /**
     * Set the root field of the query (e.g., 'nations').
     *
     * @param string $rootField
     * @return self
     */
    public function setRootField(string $rootField): self
    {
        $this->rootField = $rootField;
        return $this;
    }

    /**
     * Set the query as a mutation.
     *
     * @param bool $isMutation
     * @return self
     */
    public function setMutation(bool $isMutation = true): self
    {
        $this->isMutation = $isMutation;
        return $this;
    }

    /**
     * Add an argument or multiple arguments to the query.
     *
     * @param string|array $key
     * @param mixed $value
     * @return self
     */
    public function addArgument(string|array $key, mixed $value = null): self
    {
        if (is_array($key)) {
            // If an array is passed, merge each key-value pair into the arguments array
            foreach ($key as $argKey => $argValue) {
                $this->arguments[$argKey] = $argValue;
            }
        } else {
            // Otherwise, treat $key as a string and add the single key-value pair
            $this->arguments[$key] = $value;
        }

        return $this;
    }

    /**
     * Enable pagination information in the query.
     *
     * @return self
     */
    public function withPaginationInfo(): self
    {
        $this->includePagination = true;
        return $this;
    }

    /**
     * Build the complete query string, adding pagination if enabled.
     *
     * @return string
     */
    public function build(): string
    {
        $queryType = $this->isMutation ? 'mutation' : 'query';
        $query = $this->rootField;

        if (!empty($this->arguments)) {
            $args = [];
            foreach ($this->arguments as $key => $value) {
                $args[] = "{$key}: " . $this->formatGraphQLValue($value);
            }
            $query .= '(' . implode(', ', $args) . ')';
        }

        // Include pagination info if required
        if ($this->includePagination) {
            $this->addNestedField('paginatorInfo', function ($builder) {
                $builder->addFields(['perPage', 'count', 'lastPage']);
            });
        }

        if (!empty($this->fields)) {
            $query .= ' { ' . implode(' ', $this->fields) . ' }';
        }

        return "{$queryType} { {$query} }";
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function formatGraphQLValue(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => "\"{$value}\"",
            is_array($value) => '[' . implode(', ', array_map(fn($v) => $this->formatGraphQLValue($v), $value)) . ']',
            is_null($value) => 'null',
            default => (string)$value,
        };
    }

    /**
     * Add a nested field with its own fields (for sub-objects).
     *
     * @param string $field
     * @param callable $callback
     * @return self
     */
    public function addNestedField(string $field, callable $callback): self
    {
        $nestedBuilder = new self();
        $callback($nestedBuilder);
        $this->fields[] = "{$field} { " . $nestedBuilder->buildWithoutRoot() . " }";
        return $this;
    }

    /**
     * Build the query string without the root field (for nested fields).
     *
     * @return string
     */
    protected function buildWithoutRoot(): string
    {
        if (!empty($this->fields)) {
            return implode(' ', $this->fields);
        }
        return '';
    }

    /**
     * Add a field or set of fields to the query.
     *
     * @param array|string $fields
     * @return self
     */
    public function addFields(array|string $fields): self
    {
        if (is_array($fields)) {
            $this->fields = array_merge($this->fields, $fields);
        } else {
            $this->fields[] = $fields;
        }
        return $this;
    }
}
