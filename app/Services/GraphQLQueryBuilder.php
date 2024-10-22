<?php

namespace App\Services;

class GraphQLQueryBuilder
{
    protected string $rootField;
    protected array $arguments = [];
    protected array $fields = [];

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
     * Add an argument to the query.
     *
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function addArgument(string $key, mixed $value): self
    {
        $this->arguments[$key] = $value;
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
     * Build the complete query string.
     *
     * @return string
     */
    public function build(): string
    {
        $query = $this->rootField;

        // Add arguments if any
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

        // Add fields
        if (!empty($this->fields)) {
            $query .= ' { ' . implode(' ', $this->fields) . ' }';
        }

        return "{ {$query} }";
    }
}
