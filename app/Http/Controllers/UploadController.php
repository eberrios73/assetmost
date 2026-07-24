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
            'image' => 'required|image|max:5120',   // 5 MB; image mime enforced
        ]);
        $path = $request->file('image')->store('pasted', 'public');
        return response()->json(['url' => Storage::url($path)], 201);
    }
}
