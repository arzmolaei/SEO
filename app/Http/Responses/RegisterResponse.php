<?php

namespace App\Http\Responses;

use App\Models\User;
use App\Support\PostAuthenticationDestination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Symfony\Component\HttpFoundation\Response;

class RegisterResponse implements RegisterResponseContract
{
    public function toResponse($request): Response
    {
        /** @var Request $request */
        if ($request->wantsJson()) {
            return new JsonResponse('', 201);
        }

        /** @var User $user */
        $user = $request->user();

        return redirect()->to(PostAuthenticationDestination::for($user));
    }
}
