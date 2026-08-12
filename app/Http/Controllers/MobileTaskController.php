<?php

namespace App\Http\Controllers;

use App\Services\MyWorkService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The mobile task view (15.2): "what do I owe, by when" — one card per
 * item, one tap to act. Same feed the offline bootstrap caches.
 */
class MobileTaskController extends Controller
{
    public function __construct(private MyWorkService $myWork) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Mobile/Tasks', [
            'work' => $this->myWork->feed($request->user()),
            'pushPublicKey' => config('messaging.push.vapid_public_key'),
        ]);
    }
}
