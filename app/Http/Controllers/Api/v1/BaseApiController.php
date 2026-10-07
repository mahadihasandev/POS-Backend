<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Models\User;
use App\Traits\ApiResponses;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

abstract class BaseApiController extends Controller
{
    use ApiResponses;

    /**
     * Safely resolve the currently authenticated User model.
     */
    protected function getAuthenticatedUser(Request $request): User
    {
        /** @var User|null $user */
        $user = $request->attributes->get('authenticated_user') ?? $request->user();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthenticated request.');
        }

        return $user;
    }
}
