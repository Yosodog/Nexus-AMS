<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Customization\CustomizationImageUploadRequest;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomizationImageController extends Controller
{
    public function store(CustomizationImageUploadRequest $request): JsonResponse
    {
        $this->authorize('manage-custom-pages');

        $file = $request->file('image');
        $path = $file->store('custom-pages', 'public');

        return response()->json([
            'success' => 1,
            'file' => [
                'url' => Storage::disk('public')->url($path),
                'path' => $path,
            ],
        ], 201);
    }

    public function show(Request $request, string $token): StreamedResponse
    {
        try {
            $path = Crypt::decryptString($token);
        } catch (DecryptException) {
            abort(404);
        }

        if (! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        return Storage::disk('public')->response($path);
    }
}
