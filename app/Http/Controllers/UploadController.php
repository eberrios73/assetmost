<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Pasted screenshots land here — stored as files on the public disk, never as
 * base64 in a text column (the task list ships notes with every row; inline
 * images would bloat every request that touches it).
 */
class UploadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $request->validate([
            'image' => 'required|image|max:8192',   // 8 MB in; far less comes out
        ]);

        // Optimize on the way in: retina screenshots arrive at 3-5 MB; nobody
        // needs more than ~1600px in a doc. Downscale + WebP ≈ 10-20x smaller.
        // Any GD failure falls back to storing the original untouched.
        $file = $request->file('image');
        try {
            $img = imagecreatefromstring(file_get_contents($file->getRealPath()));
            if ($img === false) throw new \RuntimeException('undecodable');
            $w = imagesx($img); $h = imagesy($img);
            if ($w > 1600) {
                $img = imagescale($img, 1600, (int) round($h * 1600 / $w), IMG_BICUBIC);
            }
            imagepalettetotruecolor($img);
            $name = 'pasted/'.bin2hex(random_bytes(16)).'.webp';
            ob_start();
            imagewebp($img, null, 82);
            Storage::disk('public')->put($name, ob_get_clean());
            imagedestroy($img);
            return response()->json(['url' => Storage::url($name)], 201);
        } catch (\Throwable) {
            $path = $file->store('pasted', 'public');
            return response()->json(['url' => Storage::url($path)], 201);
        }
    }
}
