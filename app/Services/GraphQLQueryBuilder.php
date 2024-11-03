<?php

namespace App\Services;

class GraphQLQueryBuilder
{
    protected string $rootField;
    protected array $arguments = [];
    protected array $fields = [];
    public bool $includePagination = false;

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
     * @return string
     */
    public function getRootField(): string
    {
        return $this->rootField;
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
     * Build the complete query string, adding pagination if enabled.
     *
     * @return string
     */
    public function build(): string
    {
        $query = $this->rootField;

        if (!empty($this->arguments)) {
            $args = [];
            foreach ($this->arguments as $key => $value) {
                if (is_string($value)) {
                    $args[] = "{$key}: \"{$value}\"";
                } elseif (is_array($value)) {
                    $args[] = "{$key}: " . json_encode($value);
                } else {
                    $args[] = "{$key}: {$value}";
                }
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

        return "{ {$query} }";
    }
}
