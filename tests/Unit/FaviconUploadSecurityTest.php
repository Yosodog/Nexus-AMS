<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\StoreFaviconRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class FaviconUploadSecurityTest extends TestCase
{
    public function test_favicon_validation_rejects_svg_uploads(): void
    {
        $file = UploadedFile::fake()->createWithContent('favicon.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $validator = Validator::make(['favicon' => $file], (new StoreFaviconRequest)->rules());

        $this->assertTrue($validator->fails());
    }

    public function test_favicon_validation_accepts_png_uploads(): void
    {
        $file = UploadedFile::fake()->image('favicon.png', 32, 32);

        $validator = Validator::make(['favicon' => $file], (new StoreFaviconRequest)->rules());

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray(), JSON_THROW_ON_ERROR));
    }
}
