<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LoansIndexViewTest extends TestCase
{
    public function test_member_loans_view_compiles_to_valid_php(): void
    {
        $compiledView = Blade::compileString(
            File::get(resource_path('views/loans/index.blade.php'))
        );

        token_get_all($compiledView, TOKEN_PARSE);

        $this->addToAssertionCount(1);
    }
}
