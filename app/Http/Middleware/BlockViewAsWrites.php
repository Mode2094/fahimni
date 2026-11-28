<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only guard for the admin "View-As" mode.
 *
 * When a super admin is viewing the platform as a teacher or student, the
 * session carries a `view_as` marker. This middleware then rejects every
 * state-changing request (anything but GET/HEAD/OPTIONS) so the impersonated
 * session can never create, edit, delete, grade, pay or enroll — it can only
 * look. The two view-as control routes are explicitly allowed so the admin can
 * inspect the state and safely exit.
 *
 * Safety is server-side: even a hand-crafted request that ignores the UI is
 * refused here, so the restriction does not depend on the browser.
 */
class BlockViewAsWrites
{
    /** Writes that must remain reachable while viewing (to end the session). */
    private const CONTROL_ROUTES = [
        'admin.view-as.stop',
        'admin.view-as.show',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $viewAs = $request->session()->get('view_as');

        if (! is_array($viewAs) || empty($viewAs['user_id'])) {
            return $next($request);
        }

        if (in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        if ($request->routeIs(...self::CONTROL_ROUTES)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'وضع العرض للقراءة فقط — لا يمكن إجراء أي تعديل أثناء معاينة حساب آخر.',
            'code' => 'VIEW_AS_READ_ONLY',
        ], 403);
    }
}
