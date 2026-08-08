<?php

namespace App\Support\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ForeignIdColumnDefinition;
use Illuminate\Database\Schema\ForeignKeyDefinition;

final class WorldReferenceDefinition
{
    private ForeignIdColumnDefinition $columnDefinition;

    private ?ForeignKeyDefinition $foreignKey = null;

    private bool $hasIndex = false;

    public function __construct(
        private readonly Blueprint $table,
        private readonly string $referencedTable,
        string $column,
        private readonly bool $usesForeignKey,
        private readonly ?string $constraintName = null,
        bool $indexInHosted = true,
    ) {
        $this->columnDefinition = $this->table->foreignId($column);

        if ($this->usesForeignKey) {
            $this->foreignKey = $this->columnDefinition
                ->references('id', $this->constraintName)
                ->on($this->referencedTable);
        } elseif ($indexInHosted) {
            $this->columnDefinition->index();
            $this->hasIndex = true;
        }
    }

    public function nullable(bool $value = true): self
    {
        $this->columnDefinition->nullable($value);

        return $this;
    }

    public function after(string $column): self
    {
        $this->columnDefinition->after($column);

        return $this;
    }

    public function default(mixed $value): self
    {
        $this->columnDefinition->default($value);

        return $this;
    }

    public function comment(string $comment): self
    {
        $this->columnDefinition->comment($comment);

        return $this;
    }

    public function index(?string $name = null): self
    {
        if (! $this->hasIndex) {
            if ($name === null) {
                $this->columnDefinition->index();
            } else {
                $this->columnDefinition->index($name);
            }

            $this->hasIndex = true;
        }

        return $this;
    }

    public function unique(?string $name = null): self
    {
        if ($name === null) {
            $this->columnDefinition->unique();
        } else {
            $this->columnDefinition->unique($name);
        }

        $this->hasIndex = true;

        return $this;
    }

    public function primary(?string $name = null): self
    {
        if ($name === null) {
            $this->columnDefinition->primary();
        } else {
            $this->columnDefinition->primary($name);
        }

        $this->hasIndex = true;

        return $this;
    }

    public function constrainedInStandalone(): self
    {
        $this->reference();

        return $this;
    }

    public function cascadeOnUpdateInStandalone(): self
    {
        $this->reference()?->cascadeOnUpdate();

        return $this;
    }

    public function restrictOnUpdateInStandalone(): self
    {
        $this->reference()?->restrictOnUpdate();

        return $this;
    }

    public function nullOnUpdateInStandalone(): self
    {
        $this->reference()?->nullOnUpdate();

        return $this;
    }

    public function noActionOnUpdateInStandalone(): self
    {
        $this->reference()?->noActionOnUpdate();

        return $this;
    }

    public function cascadeOnDeleteInStandalone(): self
    {
        $this->reference()?->cascadeOnDelete();

        return $this;
    }

    public function restrictOnDeleteInStandalone(): self
    {
        $this->reference()?->restrictOnDelete();

        return $this;
    }

    public function nullOnDeleteInStandalone(): self
    {
        $this->reference()?->nullOnDelete();

        return $this;
    }

    public function noActionOnDeleteInStandalone(): self
    {
        $this->reference()?->noActionOnDelete();

        return $this;
    }

    private function reference(): ?ForeignKeyDefinition
    {
        return $this->foreignKey;
    }
}
