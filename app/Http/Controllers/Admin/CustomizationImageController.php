<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Customization\CustomizationImageUploadRequest;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Handle secure media uploads and retrieval for the customization editor.
 */
class CustomizationImageController extends Controller
{
    /**
     * Store an uploaded image and return a signed URL payload for Editor.js.
     */
    public function store(CustomizationImageUploadRequest $request): JsonResponse
    {
        $this->authorize('manage-custom-pages');

        $file = $request->file('image');
        $path = $file->store('custom-pages', 'public');

        $signedUrl = URL::temporarySignedRoute(
            'admin.customization.images.show',
            now()->addMinutes(30),
            ['token' => Crypt::encryptString($path)],
        );

        return response()->json([
            'success' => 1,
            'file' => [
                'url' => $signedUrl,
                'path' => $path,
            ],
        ], 201);
    }

    /**
     * Stream a previously uploaded image after validating the signed token.
     */
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
