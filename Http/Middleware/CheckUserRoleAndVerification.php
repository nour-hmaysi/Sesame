<?php
namespace App\Http\Middleware;

use App\Organization;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;

class CheckUserRoleAndVerification
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {


        $currentRouteName = Route::currentRouteName();
        $currentUri = $request->path();

        $excludedRoutes = [
            'generate.pdf',
            'PartnerController.getStatement',
            'PartnerController.getPayableStatement',
            'TransactionController.viewHtmlJournal',
            'login',
            'users.login',
            'register',
            'register.store',
            'auth.forgot',
            'password.sendemail',
            'password.reset',
            'password.update',
            'auth.logout',
            'verification.notice',
            'verification.send',
            'verification.verify',
            'OrganizationController.edit',
            'OrganizationController.update',
            'EmailController.send',
            'TransactionController.viewPaymentReceipt',
            'TransactionController.viewPaymentMadeReceipt',
            'InvoiceController.viewInvoice',
            'InvoiceController.viewInvoiceHtml',
            'TransactionController.viewHtmlJournal',
        ];

        $excludedUris = [
            'auth/register',
            'auth/forgot-password',
            'reset-password/{token}',
            'verify-email',
            'email/verification-notification',
            'verify-email/{id}/{hash}',
            'organization/edit/{id}',
            'organization/update/{id}',

        ];

        if (in_array($currentRouteName, $excludedRoutes) || in_array($currentUri, $excludedUris)) {
            return $next($request);
        }
        if (!Auth::check()) {
            return redirect()->guest(route('login'));
        }

        $user = Auth::user();

        if ($user && $user->role_id == 1 && !$user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        $organization = Organization::find(orgID());
        if ($organization && (empty($organization->vat_rate) || empty($organization->inv_start_nb) || empty($organization->currency_id))) {
            return redirect()->route('OrganizationController.edit', [Crypt::encryptString(orgID())])
                ->with('warning', 'Please complete your organization profile before accessing the dashboard.');
        }


        return $next($request);
    }
}
