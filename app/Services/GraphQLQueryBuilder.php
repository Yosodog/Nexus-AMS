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
     * Create a GraphQL literal (raw value) instance.
     */
    public static function literal(string $value): GraphQLLiteral
    {
        return new GraphQLLiteral($value);
    }

    public function getRootField(): string
    {
        return $this->rootField;
    }

    /**
     * Set the root field of the query (e.g., 'nations').
     */
    public function setRootField(string $rootField): self
    {
        $this->rootField = $rootField;

        return $this;
    }

    /**
     * Set the query as a mutation.
     */
    public function setMutation(bool $isMutation = true): self
    {
        $this->isMutation = $isMutation;

        return $this;
    }

    /**
     * Add an argument or multiple arguments to the query.
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
     */
    public function withPaginationInfo(): self
    {
        $this->includePagination = true;

        return $this;
    }

    /**
     * Build the complete query string, adding pagination if enabled.
     */
    public function build(): string
    {
        $queryType = $this->isMutation ? 'mutation' : 'query';
        // Include pagination info if required
        if ($this->includePagination) {
            $this->addNestedField('paginatorInfo', function ($builder) {
                $builder->addFields(['perPage', 'count', 'lastPage']);
            });
        }

        $query = $this->buildFieldSegment();

        return "{$queryType} { {$query} }";
    }

    private function formatGraphQLValue(mixed $value): string
    {
        return match (true) {
            $value instanceof GraphQLLiteral => (string) $value,
            $value instanceof \UnitEnum => $value->name,
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => '"'.addslashes($value).'"',
            is_array($value) => $this->formatArrayValue($value),
            is_null($value) => 'null',
            default => (string) $value,
        };
    }

    /**
     * Add a nested field with its own fields (for sub-objects).
     */
    public function addNestedField(string $field, callable $callback): self
    {
        $nestedBuilder = new self;
        $nestedBuilder->setRootField($field);
        $callback($nestedBuilder);
        $this->fields[] = $nestedBuilder->buildFieldSegment();

        return $this;
    }

    /**
     * Build the query string without the root field (for nested fields).
     */
    protected function buildWithoutRoot(): string
    {
        if (! empty($this->fields)) {
            return implode(' ', $this->fields);
        }

        return '';
    }

    /**
     * Add a field or set of fields to the query.
     */
    public function addFields(array|string $fields): self
    {
        if (is_array($fields)) {
            foreach ($fields as $f) {
                if (! is_string($f)) {
                    throw new \InvalidArgumentException('addFields() expects strings only.');
                }
            }
            $this->fields = array_merge($this->fields, $fields);
        } else {
            $this->fields[] = $fields;
        }

        return $this;
    }

    /**
     * Build the string for the current field including arguments and nested selections.
     */
    protected function buildFieldSegment(): string
    {
        if (! isset($this->rootField)) {
            throw new \LogicException('Root field must be set before building the query segment.');
        }

        $segment = $this->rootField;

        if (! empty($this->arguments)) {
            $args = [];
            foreach ($this->arguments as $key => $value) {
                $args[] = "{$key}: ".$this->formatGraphQLValue($value);
            }
            $segment .= '('.implode(', ', $args).')';
        }

        if (! empty($this->fields)) {
            $segment .= ' { '.implode(' ', $this->fields).' }';
        }

        return $segment;
    }

    private function formatArrayValue(array $value): string
    {
        if (array_is_list($value)) {
            return '['.implode(', ', array_map(fn ($v) => $this->formatGraphQLValue($v), $value)).']';
        }

        $pairs = [];
        foreach ($value as $key => $item) {
            $pairs[] = "{$key}: ".$this->formatGraphQLValue($item);
        }

        return '{ '.implode(', ', $pairs).' }';
    }
}
