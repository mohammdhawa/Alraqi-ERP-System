<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * The account still holds the password HR issued it.
 *
 * 403, not 401: the token is perfectly valid and the caller is who they say
 * they are. What is refused is the ACTION, until the shared credential has been
 * replaced — so a client must not react by refreshing its token or bouncing the
 * user back to the login screen, which is exactly what a 401 would tell it to
 * do.
 *
 * Thrown by EnsurePasswordIsChanged, which lets through only the three routes
 * that resolve the situation or end the session.
 */
class PasswordChangeRequiredException extends AuthenticationException
{
    public function __construct(
        string $message = 'يجب تغيير كلمة المرور المؤقتة قبل متابعة استخدام النظام.',
    ) {
        parent::__construct($message, Response::HTTP_FORBIDDEN);
    }
}
