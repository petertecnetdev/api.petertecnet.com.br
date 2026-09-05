<?php

namespace App\Http\Controllers;

use App\Services\SocialAuthService;
use Illuminate\Http\Request;

class SocialAuthController extends Controller
{
    public function __construct(private readonly SocialAuthService $socialAuth)
    {
    }

    public function instagramStart()
    {
        return $this->socialAuth->instagramStart();
    }

    public function instagramCallback(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:4096',
            'state' => 'required|string|max:8192',
        ]);

        return $this->socialAuth->instagramCallback($data);
    }

    public function instagramComplete(Request $request)
    {
        $data = $request->validate([
            'completion_token' => 'required|string|min:40|max:255',
            'email' => 'required|email|max:255',
            'first_name' => 'nullable|string|max:100',
        ]);

        return $this->socialAuth->instagramComplete($data);
    }

    public function instagramLink(Request $request)
    {
        $data = $request->validate([
            'completion_token' => 'required|string|min:40|max:255',
        ]);

        return $this->socialAuth->instagramLink($request->user('api'), $data);
    }
}
