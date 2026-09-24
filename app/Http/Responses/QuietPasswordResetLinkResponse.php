<?php

namespace App\Http\Responses;

use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;

/**
 * "We can't find a user with that email address" tells anyone which addresses
 * have an account, and "please wait before retrying" says the same, because
 * only an existing account is throttled. Whatever happened, the form answers
 * as it does when the link was sent. A badly formed address never gets here:
 * validation refuses it first.
 */
class QuietPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse
{
    public function __construct(protected string $status) {}

    public function toResponse($request)
    {
        $message = trans(Password::RESET_LINK_SENT);

        return $request->wantsJson()
            ? response()->json(['message' => $message])
            : back()->with('status', $message);
    }
}
